<?php

namespace ZeamPass\Settlement;

use ZeamPass\Address;
use ZeamPass\BatchSettlement;
use ZeamPass\Big;
use ZeamPass\Hex;
use ZeamPass\Pass;
use ZeamPass\Secp256k1;

final class Refunds
{
    const FUTURE_SKEW_MS = 30000;

    private $cfg;
    private $store;
    private $chain;
    private $relay;
    private $gas;
    private $channels;

    public function __construct(Config $cfg, Store $store, ?Chain $chain = null, ?Relay $relay = null)
    {
        $this->cfg = $cfg;
        $this->store = $store;
        $this->chain = $chain ?: new Chain($cfg->rpcUrl);
        $this->relay = $relay ?: new Relay($cfg->relayUrl);
        $this->gas = new GasQuote($cfg, $this->chain, $this->relay);
        $this->channels = new Channels($cfg, $store, $this->chain);
    }

    public static function message($channelId, $issued)
    {
        return Pass::REALM . " refund\nchannel: " . strtolower((string) $channelId) . "\nissued: " . $issued;
    }

    private static function answer($status, array $body)
    {
        return ['status' => $status, 'body' => $body];
    }

    private static function failed($status, $code, $why, array $more = [])
    {
        return self::answer($status, array_merge(['op' => 'refund_failed', 'code' => $code, 'why' => $why], $more));
    }

    public function proofOwner($channelId, $issued, $signature)
    {
        $t = null;
        if (is_string($issued) && trim($issued) !== '') {
            try {
                $d = new \DateTimeImmutable($issued);
                $t = (int) round((float) $d->format('U.u') * 1000);
            } catch (\Throwable $e) {
                $t = null;
            }
        }
        if ($t === null) {
            return ['ok' => false, 'why' => 'issued is not a timestamp'];
        }
        $now = $this->cfg->nowMs();
        $window = 1000 * (int) $this->cfg->refundWindowSecs;
        if ($now - $t > $window || $t - $now > self::FUTURE_SKEW_MS) {
            return ['ok' => false, 'why' => 'issued ' . $issued . ' is outside the ' . (int) $this->cfg->refundWindowSecs . 's window'];
        }
        try {
            $who = Secp256k1::recoverPersonal(self::message($channelId, $issued), (string) $signature);
        } catch (\Throwable $e) {
            return ['ok' => false, 'why' => 'signature does not recover: ' . \ZeamPass\message($e)];
        }
        return ['ok' => true, 'address' => strtolower($who)];
    }

    public static function owns(array $channel, $address)
    {
        $who = strtolower((string) $address);
        if ($who === '') {
            return false;
        }
        $payer = strtolower(isset($channel['channelConfig']['payer']) ? $channel['channelConfig']['payer'] : '');
        $auth = strtolower(isset($channel['channelConfig']['payerAuthorizer']) ? $channel['channelConfig']['payerAuthorizer'] : '');
        return $who === $payer || ($auth !== '' && $who === $auth);
    }

    const SELF_SEND = '{"selfSend": true}';

    public function handle($channelId, $issued, $signature, array $ask = [])
    {
        $cid = strtolower((string) $channelId);
        $ch = Verify::isCanonicalChannelId($cid) ? $this->channels->repaired($cid) : null;
        $sent = isset($ask['channelConfig']) ? $ask['channelConfig'] : null;
        $own = $sent === null ? null : $this->ownConfig($cid, $sent);
        if (isset($own['mismatch'])) {
            return self::failed(400, 'channel_config_mismatch', $own['why'], ['mismatch' => $own['mismatch']]);
        }
        $config = isset($own['config']) ? $own['config'] : null;
        if ($ch === null && $config === null) {
            return self::failed(404, 'unknown_channel', 'no channel with that id here. For a channel with no calls here, send its channelConfig with the proof.');
        }
        $proof = $this->proofOwner($cid, $issued, $signature);
        if (!$proof['ok']) {
            return self::failed(400, 'proof_invalid', $proof['why'], ['sign' => self::message($cid, '<ISO8601 within five minutes>')]);
        }
        if (!self::owns($ch !== null ? $ch : ['channelConfig' => $config], $proof['address'])) {
            return self::failed(403, 'not_the_payer', $proof['address'] . ' is not this channel\'s payer');
        }
        if ($ch === null) {
            $adopted = $this->adopt($cid, $config);
            if (isset($adopted['failed'])) {
                return $adopted['failed'];
            }
            $ch = $adopted['channel'];
        }
        return $this->refundNow($ch, $ask);
    }

    private function ownConfig($cid, $config)
    {
        $fields = ['mismatch' => 'fields', 'why' => 'channelConfig needs payer, payerAuthorizer, receiver, receiverAuthorizer, token, withdrawDelay and salt, as deposited'];
        if (!is_array($config) || ($config !== [] && array_keys($config) === range(0, count($config) - 1))) {
            return $fields;
        }
        try {
            $id = strtolower(Verify::computeChannelId($config, $this->cfg->network));
        } catch (\Throwable $e) {
            return $fields;
        }
        if ($id !== $cid) {
            return ['mismatch' => 'channelId', 'why' => 'channelConfig hashes to ' . $id . ', not to channelId ' . $cid];
        }
        if (!Address::equals($config['receiver'], $this->cfg->receiver)) {
            return ['mismatch' => 'receiver', 'why' => 'channelConfig receiver is ' . strtolower((string) $config['receiver']) . '; this seller\'s is ' . strtolower((string) $this->cfg->receiver)];
        }
        if (!Address::equals($config['receiverAuthorizer'], $this->cfg->receiverAuthorizer)) {
            return ['mismatch' => 'receiverAuthorizer', 'why' => 'channelConfig receiverAuthorizer is ' . strtolower((string) $config['receiverAuthorizer']) . '; this seller\'s is ' . strtolower((string) $this->cfg->receiverAuthorizer)];
        }
        if ($this->cfg->assetOf($config['token']) === null) {
            return ['mismatch' => 'token', 'why' => 'channelConfig token ' . strtolower((string) $config['token']) . ' is not accepted here'];
        }
        return ['config' => $config];
    }

    private function adopt($cid, array $config)
    {
        try {
            $st = $this->chain->channel($cid);
        } catch (\Throwable $e) {
            return ['failed' => self::failed(409, 'chain_unreadable', 'could not read the channel on chain', ['retry_after_seconds' => self::RETRY_SECS])];
        }
        if (Big::sign(Channels::n($st['balance'])) <= 0) {
            return ['failed' => self::failed(400, 'channel_config_mismatch', 'channelConfig names this seller, but that channel holds no balance on chain: its escrow balance is 0', ['mismatch' => 'balance'])];
        }
        $now = $this->cfg->nowMs();
        $channel = $this->channels->update($cid, function ($r) use ($cid, $config, $st, $now) {
            return $r !== null ? $r : [
                'channelId' => $cid,
                'channelConfig' => $config,
                'chargedCumulativeAmount' => (string) $st['totalClaimed'],
                'balance' => (string) $st['balance'],
                'totalClaimed' => (string) $st['totalClaimed'],
                'withdrawRequestedAt' => 0,
                'refundNonce' => 0,
                'onchainSyncedAt' => $now,
                'lastRequestTimestamp' => 0,
                'adoptedAt' => $now,
            ];
        });
        return ['channel' => $channel];
    }

    const AUTHORIZATION_FIELDS = ['from', 'to', 'value', 'validAfter', 'validBefore', 'nonce'];
    const GAS_PAYMENT_MARGIN_SECS = 30;
    const RETRY_SECS = 60;
    const REFUND_EVERY_SECS = 3600;

    public static function ask($body)
    {
        $b = is_array($body) ? $body : [];
        $obj = function ($x) {
            return is_array($x) && ($x === [] || array_keys($x) !== range(0, count($x) - 1)) ? $x : null;
        };
        $scalar = function ($x) {
            if (is_int($x) || is_string($x)) {
                return (string) $x;
            }
            if (is_float($x)) {
                return is_finite($x) && floor($x) === $x && abs($x) < 1e21 ? sprintf('%.0f', $x) : json_encode($x);
            }
            return null;
        };
        $p = $obj(isset($b['gasPayment']) ? $b['gasPayment'] : null);
        $a = $p === null ? null : $obj(isset($p['authorization']) ? $p['authorization'] : null);
        $config = $obj(isset($b['channelConfig']) ? $b['channelConfig'] : null);
        $authorization = null;
        if ($a !== null) {
            $authorization = [];
            foreach (self::AUTHORIZATION_FIELDS as $k) {
                $authorization[$k] = $scalar(isset($a[$k]) ? $a[$k] : null);
            }
        }
        return [
            'selfSend' => (isset($b['selfSend']) && ($b['selfSend'] === true || $b['selfSend'] === 'true')),
            'channelConfig' => $config !== null ? $config : (isset($b['channelConfig']) ? $b['channelConfig'] : null),
            'gasPayment' => $p === null ? null : [
                'authorization' => $authorization,
                'signature' => $scalar(isset($p['signature']) ? $p['signature'] : null),
            ],
        ];
    }

    private function microUSD(array $ch, $units)
    {
        return $this->cfg->microUSDOf($ch['channelConfig']['token'], $units);
    }

    public static function usd($microUSD)
    {
        $s = rtrim(rtrim(sprintf('%.6f', $microUSD / 1e6), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    private function frozen(array $raw, array $live, array $ch, $left)
    {
        if ($this->cfg->meter === null) {
            return ['ch' => $ch, 'left' => $left];
        }
        if (!$this->cfg->meter->stop($ch['channelId'])) {
            return ['failed' => self::failed(409, 'request_open', 'a call is running on this channel\'s line. Retry in 5s.', ['retry_after_seconds' => 5])];
        }
        $now = Channels::earned($this->cfg, $raw);
        $rest = Channels::refundable($now, $live);
        if (Big::sign(Channels::n($rest)) <= 0) {
            return ['failed' => self::failed(409, 'nothing_to_return', 'nothing to return: the channel is fully spent', ['leftMicroUSD' => 0, 'returnedMicroUSD' => 0, 'gasMicroUSD' => 0])];
        }
        return ['ch' => $now, 'left' => $rest];
    }

    private function refundNow(array $raw, array $ask = [])
    {
        $id = strtolower($raw['channelId']);
        $ch = Channels::earned($this->cfg, $raw);
        try {
            $live = $this->chain->live($id);
        } catch (\Throwable $e) {
            return self::failed(409, 'chain_unreadable', 'could not read the channel on chain', ['retry_after_seconds' => self::RETRY_SECS]);
        }
        $left = Channels::refundable($ch, $live);
        if (Big::sign(Channels::n($left)) <= 0) {
            return self::failed(409, 'nothing_to_return', 'nothing to return: the channel is fully spent', ['leftMicroUSD' => 0, 'returnedMicroUSD' => 0, 'gasMicroUSD' => 0]);
        }
        $now = $this->cfg->nowMs();
        if (Channels::pendingLive($ch, $now)) {
            $wait = (int) ceil(((int) $ch['pendingRequest']['expiresAt'] - $now) / 1000);
            return self::failed(409, 'request_open', "a paid call is open on this channel. Retry in {$wait}s.", ['retry_after_seconds' => $wait]);
        }
        try {
            $nonce = $this->chain->refundNonce($id);
        } catch (\Throwable $e) {
            return self::failed(409, 'chain_unreadable', 'could not read the refund nonce on chain', ['retry_after_seconds' => self::RETRY_SECS]);
        }
        if (isset($ask['selfSend']) && $ask['selfSend'] === true) {
            $f = $this->frozen($raw, $live, $ch, $left);
            return isset($f['failed']) ? $f['failed'] : $this->selfSend($f['ch'], $live, $f['left'], $nonce);
        }
        $since = $now - (int) (isset($ch['lastRefundAt']) ? $ch['lastRefundAt'] : 0);
        if ($since < 1000 * self::REFUND_EVERY_SECS) {
            $wait = (int) ceil((1000 * self::REFUND_EVERY_SECS - $since) / 1000);
            return self::failed(409, 'refund_too_soon', '1 refund per channel per hour. Retry in ' . $wait . 's, or send ' . self::SELF_SEND . ' for a signed refund to send yourself.', ['retry_after_seconds' => $wait, 'leftMicroUSD' => $this->microUSD($ch, $left)]);
        }
        $f = $this->frozen($raw, $live, $ch, $left);
        if (isset($f['failed'])) {
            return $f['failed'];
        }
        $ch = $f['ch'];
        $left = $f['left'];
        $leftMicroUSD = $this->microUSD($ch, $left);
        try {
            $call = $this->refundCall($ch, $live, $left, $nonce);
        } catch (\Throwable $e) {
            return self::failed(409, 'refund_error', substr(\ZeamPass\message($e), 0, 160), ['retry_after_seconds' => self::RETRY_SECS, 'leftMicroUSD' => $leftMicroUSD]);
        }
        $gas = $this->refundGas($ch, $live, $call);
        if ($gas === null) {
            return self::failed(503, 'gas_unpriced', 'refund gas cannot be priced. Retry in 60s, or send ' . self::SELF_SEND . ' for a signed refund to send yourself.', ['retry_after_seconds' => self::RETRY_SECS, 'leftMicroUSD' => $leftMicroUSD]);
        }
        $amounts = ['leftMicroUSD' => $leftMicroUSD, 'returnedMicroUSD' => $leftMicroUSD, 'gasMicroUSD' => $gas['gasMicroUSD'], 'feeMicroUSD' => $gas['feeMicroUSD'], 'coverMicroUSD' => $gas['coverMicroUSD']];
        $payment = null;
        if (!$gas['covered']) {
            $asset = $this->cfg->assetOf($ch['channelConfig']['token']);
            $rule = 'channel fees $' . self::usd($gas['feeMicroUSD']) . ' < deposit and refund gas $' . self::usd($gas['coverMicroUSD']);
            if ($asset === null || empty($asset['eip3009'])) {
                return self::failed(409, 'nothing_to_return', $rule . '; this token has no gasless payment for the $' . self::usd($gas['gasMicroUSD']) . ' refund gas. Send ' . self::SELF_SEND . ' for a signed refund of the full balance, sent at your own gas.', array_merge($amounts, ['returnedMicroUSD' => 0], $gas['detail']));
            }
            if ($gas['gasMicroUSD'] >= $leftMicroUSD) {
                return self::failed(409, 'nothing_to_return', $rule . '; refund gas $' . self::usd($gas['gasMicroUSD']) . ' >= balance $' . self::usd($leftMicroUSD) . '. Send ' . self::SELF_SEND . ' for a signed refund of the full balance, sent at your own gas.', array_merge($amounts, ['returnedMicroUSD' => 0], $gas['detail']));
            }
            $payTo = $this->gas->gasWallet();
            if ($payTo === null) {
                return self::failed(503, 'gas_unpriced', 'the relay is unreachable. Retry in 60s, or send ' . self::SELF_SEND . ' for a signed refund to send yourself.', ['retry_after_seconds' => self::RETRY_SECS, 'leftMicroUSD' => $leftMicroUSD]);
            }
            $units = $this->cfg->unitsOfMicroUSD($asset, $gas['gasMicroUSD']);
            $offered = isset($ask['gasPayment']) ? $ask['gasPayment'] : null;
            if ($offered === null) {
                return $this->gasQuote($ch, $asset, $payTo, $units, $amounts, $gas, null);
            }
            $bad = $this->gasPaymentProblem($ch, $asset, $payTo, $this->cfg->unitsOfMicroUSD($asset, $gas['costMicroUSD']), $left, $offered);
            if ($bad !== null) {
                return $this->gasQuote($ch, $asset, $payTo, $units, $amounts, $gas, $bad);
            }
            $payment = ['asset' => $asset, 'payTo' => $payTo, 'authorization' => $offered['authorization'], 'signature' => $offered['signature']];
        }
        if ($payment === null) {
            return $this->sendRefund($ch, $call, $left, 0, $amounts);
        }
        $pay = ['to' => $payment['asset']['address'], 'data' => BatchSettlement::encodeTransferWithAuthorization($payment['authorization'], $payment['signature'])];
        $bundle = ['to' => BatchSettlement::MULTICALL3, 'data' => BatchSettlement::encodeAggregate3([$call, $pay])];
        $out = $this->sendRefund($ch, $bundle, $left, $this->microUSD($ch, $payment['authorization']['value']), $amounts);
        return isset($out['underpaid']) ? $this->requote($ch, $live, $payment, $amounts, $gas, $out['underpaid'], $call) : $out;
    }

    private function requote(array $ch, array $live, array $payment, array $amounts, array $gas, $sendCost, $call = null)
    {
        $fresh = $this->refundGas($ch, $live, $call);
        if ($fresh === null) {
            $fresh = $gas;
        }
        $quoted = max($fresh['covered'] ? 0 : $fresh['gasMicroUSD'], (int) ceil($sendCost * $this->cfg->gasBuffer));
        $numbers = array_merge($amounts, ['gasMicroUSD' => $quoted, 'feeMicroUSD' => $fresh['feeMicroUSD'], 'coverMicroUSD' => $fresh['coverMicroUSD'], 'sendCostMicroUSD' => $sendCost]);
        $moved = 'refund gas rose past the quote margin: sending costs $' . self::usd($sendCost) . ', your payment is $' . self::usd($this->microUSD($ch, $payment['authorization']['value'])) . '. Nothing was sent';
        if ($quoted >= $amounts['leftMicroUSD']) {
            return self::failed(409, 'nothing_to_return', $moved . '; refund gas $' . self::usd($quoted) . ' (margin included) >= balance $' . self::usd($amounts['leftMicroUSD']) . '. Send ' . self::SELF_SEND . ' for a signed refund of the full balance, sent at your own gas.', array_merge($numbers, ['returnedMicroUSD' => 0], $fresh['detail']));
        }
        $units = $this->cfg->unitsOfMicroUSD($payment['asset'], $quoted);
        return $this->gasQuote($ch, $payment['asset'], $payment['payTo'], $units, $numbers, $fresh, $moved . '. New quote: that cost + ' . $fresh['detail']['marginPercent'] . '% margin', ['requoted' => true, 'sendCostMicroUSD' => $sendCost]);
    }

    private function selfSend(array $ch, array $live, $left, $nonce)
    {
        if (!empty($ch['handedOver']['transaction']) && (string) $ch['handedOver']['nonce'] === (string) $nonce) {
            return self::answer(200, [
                'op' => 'refund_signed',
                'microUSD' => $this->microUSD($ch, isset($ch['handedOver']['units']) ? $ch['handedOver']['units'] : '0'),
                'transaction' => $ch['handedOver']['transaction'],
                'why' => 'your signed refund is unsent; here it is again',
            ]);
        }
        try {
            $call = $this->refundCall($ch, $live, $left, $nonce);
        } catch (\Throwable $e) {
            return self::failed(409, 'refund_error', substr(\ZeamPass\message($e), 0, 160), ['retry_after_seconds' => self::RETRY_SECS]);
        }
        return $this->handOver($ch, $live, $call, $left, $nonce);
    }

    public function refundGas(array $ch, array $live, $call = null)
    {
        $bundled = self::bundledClaim($ch, $live);
        $q = $this->gas->refund(Big::sign($bundled) > 0, true);
        if ($q === null) {
            return null;
        }
        $cover = $q['cover'];
        $earned = $this->microUSD($ch, Big::strval(Big::add(Channels::n($live['claimed']), $bundled)));
        $feeMicroUSD = GasQuote::feeMicroUSD($earned, $this->cfg->feeShare);
        $detailOf = function (array $p) {
            return ['gasUnits' => $p['gasUnits'], 'gasUnitsWithMargin' => $p['gasUnitsWithMargin'], 'gasPriceWei' => $p['gasPriceWei'], 'ethUSD' => $p['ethUSD'], 'l1FeeWei' => $p['l1FeeWei'], 'marginPercent' => $p['marginPercent'], 'quotedBy' => $p['quotedBy']];
        };
        if ($feeMicroUSD >= $cover['microUSD']) {
            return ['covered' => true, 'gasMicroUSD' => 0, 'costMicroUSD' => 0, 'feeMicroUSD' => $feeMicroUSD, 'coverMicroUSD' => $cover['microUSD'], 'detail' => $detailOf($q['pay'])];
        }
        $pay = $this->gas->relayQuote($call);
        if ($pay === null) {
            $pay = $q['pay'];
        }
        return ['covered' => false, 'gasMicroUSD' => $pay['microUSD'], 'costMicroUSD' => $pay['costMicroUSD'], 'feeMicroUSD' => $feeMicroUSD, 'coverMicroUSD' => $cover['microUSD'], 'detail' => $detailOf($pay)];
    }

    private function gasPaymentProblem(array $ch, array $asset, $payTo, $units, $left, array $p)
    {
        $a = isset($p['authorization']) ? $p['authorization'] : null;
        $missing = !is_array($a) || !isset($p['signature']) || $p['signature'] === null;
        if (!$missing) {
            foreach (self::AUTHORIZATION_FIELDS as $k) {
                if (!isset($a[$k]) || $a[$k] === null) {
                    $missing = true;
                }
            }
        }
        if ($missing) {
            return 'gasPayment is {authorization: {from, to, value, validAfter, validBefore, nonce}, signature}';
        }
        if (!Address::equals($a['from'], $ch['channelConfig']['payer'])) {
            return 'gasPayment must come from the channel\'s payer';
        }
        if (!Address::equals($a['to'], $payTo)) {
            return 'gasPayment must go to the relay gas wallet ' . $payTo;
        }
        if (!preg_match('/^\d+$/D', $a['value']) || Big::cmp(Channels::n($a['value']), Channels::n($units)) < 0) {
            return 'gasPayment is ' . $a['value'] . '; sending costs ' . $units;
        }
        if (Big::cmp(Channels::n($a['value']), Channels::n($left)) > 0) {
            return 'gasPayment is ' . $a['value'] . ', over the ' . $left . ' refund';
        }
        $nowSecs = Big::init((string) intdiv($this->cfg->nowMs(), 1000), 10);
        if (!preg_match('/^\d+$/D', $a['validAfter']) || Big::cmp(Channels::n($a['validAfter']), $nowSecs) > 0) {
            return 'gasPayment is not valid yet';
        }
        if (!preg_match('/^\d+$/D', $a['validBefore']) || Big::cmp(Channels::n($a['validBefore']), Big::add($nowSecs, Big::init(self::GAS_PAYMENT_MARGIN_SECS))) < 0) {
            return 'gasPayment expires too soon';
        }
        if (!Hex::isHex($a['nonce'], 32)) {
            return 'gasPayment nonce is not 32 bytes';
        }
        try {
            $signer = Secp256k1::recoverHash(BatchSettlement::transferAuthorizationDigest(self::tokenDomain($this->cfg, $asset), $a), $p['signature']);
        } catch (\Throwable $e) {
            $signer = null;
        }
        if ($signer === null || !Address::equals($signer, $a['from'])) {
            return 'gasPayment signature is not the payer\'s';
        }
        return null;
    }

    public static function tokenDomain(Config $cfg, array $asset)
    {
        return BatchSettlement::tokenDomain($asset['address'], $asset['name'], $asset['version'] !== null ? $asset['version'] : '1', $cfg->chainId);
    }

    private function gasQuote(array $ch, array $asset, $payTo, $units, array $amounts, array $gasInfo, $why, array $extra = [])
    {
        $nowSecs = intdiv($this->cfg->nowMs(), 1000);
        $secs = (int) $this->cfg->gasPaymentSecs;
        $authorization = [
            'from' => Address::checksum($ch['channelConfig']['payer']),
            'to' => Address::checksum($payTo),
            'value' => (string) $units,
            'validAfter' => '0',
            'validBefore' => (string) ($nowSecs + $secs),
            'nonce' => '0x' . bin2hex(random_bytes(32)),
        ];
        $gas = $this->microUSD($ch, $units);
        $d = $gasInfo['detail'];
        $lead = $why === null ? '' : $why . '. ';
        $amounts['gasMicroUSD'] = $gas;
        return self::answer(409, array_merge([
            'op' => 'refund_quote',
            'code' => 'gas_payment_needed',
            'why' => $lead . 'channel fees $' . self::usd($gasInfo['feeMicroUSD']) . ' < deposit and refund gas $' . self::usd($gasInfo['coverMicroUSD']) . '. Refund gas: $' . self::usd($gas) . ' USDC, no ETH (' . $d['gasUnits'] . ' gas, L1 fee ' . $d['l1FeeWei'] . ' wei, ' . $d['gasPriceWei'] . ' wei per gas, $' . Json::encode($d['ethUSD']) . ' per ETH, ' . $d['marginPercent'] . '% margin; ' . ($d['quotedBy'] === 'relay' ? 'priced by the relay' : 'priced from measured refunds') . '). Sign authorization (EIP-3009 TransferWithAuthorization; typed data in sign) and POST again with gasPayment: {authorization, signature} within ' . $secs . 's. The refund of $' . self::usd($amounts['leftMicroUSD']) . ' and the gas payment go in one transaction: $' . self::usd($amounts['leftMicroUSD'] - $gas) . ' net to you. Or send ' . self::SELF_SEND . ' for a signed refund of the full balance, sent at your own gas.',
        ], $amounts, $d, $extra, [
            'payTo' => $authorization['to'],
            'authorization' => $authorization,
            'sign' => BatchSettlement::transferAuthorizationTypedData(self::tokenDomain($this->cfg, $asset), $authorization),
        ]));
    }

    public static function bundledClaim(array $ch, array $live)
    {
        $charged = Channels::n(isset($ch['chargedCumulativeAmount']) ? $ch['chargedCumulativeAmount'] : '0');
        if (Big::cmp($charged, Channels::n($live['claimed'])) <= 0 || empty($ch['signature']) || empty($ch['signedMaxClaimable'])) {
            return Big::init(0);
        }
        return Big::sub($charged, Channels::n($live['claimed']));
    }

    public function refundCall(array $ch, array $live, $left, $nonce)
    {
        $id = strtolower($ch['channelId']);
        $refundSig = BatchSettlement::signRefund($this->cfg->signingKey(), $id, $left, $nonce, $this->cfg->chainId);
        $refund = BatchSettlement::encodeRefundWithSignature($ch['channelConfig'], $left, $nonce, $refundSig);
        if (Big::sign(self::bundledClaim($ch, $live)) <= 0) {
            return ['to' => BatchSettlement::ESCROW, 'data' => $refund];
        }
        $claims = [Channels::claimEntry($ch)];
        $claimSig = BatchSettlement::signClaimBatch($this->cfg->signingKey(), $claims, $this->cfg->chainId);
        return [
            'to' => BatchSettlement::ESCROW,
            'data' => BatchSettlement::encodeMulticall([BatchSettlement::encodeClaimWithSignature($claims, $claimSig), $refund]),
        ];
    }

    private function sendRefund(array $ch, array $call, $left, $gasMicroUSD, array $amounts = [])
    {
        $id = strtolower($ch['channelId']);
        $sent = $this->relay->send([$call], $this->cfg->relaySplit());
        if (!isset($sent[0]['hash'])) {
            $error = isset($sent[0]['error']) ? (string) $sent[0]['error'] : 'the relay did not send the refund';
            $numbers = [
                'leftMicroUSD' => isset($amounts['leftMicroUSD']) ? $amounts['leftMicroUSD'] : null,
                'gasMicroUSD' => $gasMicroUSD,
                'feeMicroUSD' => isset($amounts['feeMicroUSD']) ? $amounts['feeMicroUSD'] : null,
                'coverMicroUSD' => isset($amounts['coverMicroUSD']) ? $amounts['coverMicroUSD'] : null,
            ];
            if ($gasMicroUSD > 0 && isset($sent[0]['code']) && $sent[0]['code'] === 'underpaid') {
                return ['underpaid' => max(1, isset($sent[0]['gasMicroUSD']) ? (int) $sent[0]['gasMicroUSD'] : 0)];
            }
            if (preg_match('/one refund an hour/', $error)) {
                $wait = preg_match('/retry in (\d+)s/', $error, $m) ? (int) $m[1] : self::REFUND_EVERY_SECS;
                return self::failed(409, 'refund_too_soon', '1 refund per channel per hour. Retry in ' . $wait . 's, or send ' . self::SELF_SEND . ' for a signed refund to send yourself.', array_merge(['retry_after_seconds' => $wait], $numbers));
            }
            return self::failed(409, 'refund_error', substr($error, 0, 160), array_merge(['retry_after_seconds' => self::RETRY_SECS], $numbers));
        }
        $after = null;
        for ($i = 0; $i < max(1, (int) $this->cfg->drainTries); $i++) {
            try {
                $after = $this->chain->live($id);
            } catch (\Throwable $e) {
                $after = null;
            }
            if ($after !== null && Big::cmp(Channels::n($after['balance']), Channels::n($after['claimed'])) <= 0) {
                break;
            }
            if ($i + 1 < (int) $this->cfg->drainTries) {
                $this->cfg->sleepMs($this->cfg->drainWaitMs);
            }
        }
        $drained = $after !== null && Big::cmp(Channels::n($after['balance']), Channels::n($after['claimed'])) <= 0;
        $at = $this->cfg->nowMs();
        $state = null;
        $this->channels->update($id, function ($r) use ($after, $at, $ch) {
            if ($r === null) {
                return null;
            }
            $r['lastRefundAt'] = $at;
            if ($after === null && Big::cmp(Channels::n(isset($ch['chargedCumulativeAmount']) ? $ch['chargedCumulativeAmount'] : '0'), Channels::n(isset($r['chargedCumulativeAmount']) ? $r['chargedCumulativeAmount'] : '0')) < 0) {
                $r['chargedCumulativeAmount'] = $ch['chargedCumulativeAmount'];
            }
            if ($after !== null) {
                $r['balance'] = $after['balance'];
                $r['totalClaimed'] = $after['claimed'];
                $r['chargedCumulativeAmount'] = $after['claimed'];
                $r['refundNonce'] = (int) (isset($r['refundNonce']) ? $r['refundNonce'] : 0) + 1;
                unset($r['signedMaxClaimable'], $r['signature'], $r['pendingRequest'], $r['handedOver']);
            }
            return $r;
        });
        if ($after !== null) {
            $state = ['channelId' => $id, 'balance' => $after['balance'], 'totalClaimed' => $after['claimed'], 'chargedCumulativeAmount' => $after['claimed']];
        }
        $timeMs = $this->cfg->meter !== null ? $this->cfg->meter->forget($id) : 0;
        $returned = $this->microUSD($ch, $left);
        $body = ['op' => 'refunded', 'microUSD' => $returned, 'returnedMicroUSD' => $returned, 'gasMicroUSD' => $gasMicroUSD, 'transaction' => $sent[0]['hash'], 'drained' => $drained];
        if ($timeMs > 0) {
            $body['timeReturnedMs'] = $timeMs;
        }
        $body['why'] = $gasMicroUSD > 0
            ? 'refund $' . self::usd($returned) . ' and gas payment $' . self::usd($gasMicroUSD) . ' (' . $this->gas->marginPercent() . '% margin included) sent in one transaction: $' . self::usd($returned - $gasMicroUSD) . ' net to you'
            : 'refund $' . self::usd($returned) . ' sent; ZEAM paid the gas';
        if ($state !== null) {
            $body['channelState'] = $state;
        }
        return self::answer(200, $body);
    }

    private function handOver(array $ch, array $live, array $call, $left, $nonce)
    {
        $id = strtolower($ch['channelId']);
        $now = $this->cfg->nowMs();
        $transaction = ['chainId' => $this->cfg->chainId, 'to' => Address::checksum($call['to']), 'data' => $call['data'], 'value' => '0'];
        $before = null;
        $earned = isset($ch['chargedCumulativeAmount']) ? (string) $ch['chargedCumulativeAmount'] : '0';
        try {
            $this->channels->update($id, function ($r) use ($left, $live, $nonce, $now, $transaction, $earned, &$before) {
                if ($r === null) {
                    return null;
                }
                if (Big::cmp(Channels::n($earned), Channels::n(isset($r['chargedCumulativeAmount']) ? $r['chargedCumulativeAmount'] : '0')) < 0) {
                    $r['chargedCumulativeAmount'] = $earned;
                }
                $before = isset($r['balance']) ? $r['balance'] : '0';
                $rest = Big::sub(Channels::n($before), Channels::n($left));
                $r['balance'] = Big::sign($rest) > 0 ? Big::strval($rest) : '0';
                $r['handedOver'] = [
                    'units' => (string) $left,
                    'chainBalance' => (string) $live['balance'],
                    'nonce' => (string) $nonce,
                    'at' => $now,
                    'transaction' => $transaction,
                ];
                return $r;
            });
        } catch (\Throwable $e) {
            return self::failed(409, 'refund_error', substr(\ZeamPass\message($e), 0, 160), ['retry_after_seconds' => self::RETRY_SECS]);
        }
        $timeMs = $this->cfg->meter !== null ? $this->cfg->meter->forget($id) : 0;
        $body = [
            'op' => 'refund_signed',
            'microUSD' => $this->microUSD($ch, $left),
            'transaction' => $transaction,
            'why' => 'signed refund of the full balance; send it at your own gas',
        ];
        if ($timeMs > 0) {
            $body['timeReturnedMs'] = $timeMs;
        }
        return self::answer(200, $body);
    }
}
