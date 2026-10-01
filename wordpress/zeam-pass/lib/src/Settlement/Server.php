<?php

namespace ZeamPass\Settlement;

use ZeamPass\BatchSettlement;
use ZeamPass\Big;
use ZeamPass\Hex;
use ZeamPass\Pricing;

final class Server
{
    const MISMATCH = Reason::CUMULATIVE_AMOUNT_MISMATCH;
    const MIN_PENDING_TTL_MS = 5000;
    const MAX_PENDING_TTL_MS = 600000;
    const SDK_FIELDS = [
        'channelId', 'channelConfig', 'chargedCumulativeAmount', 'signedMaxClaimable', 'signature', 'balance', 'totalClaimed',
        'withdrawRequestedAt', 'refundNonce', 'onchainSyncedAt', 'lastRequestTimestamp', 'pendingRequest',
    ];

    private $cfg;
    private $store;
    private $chain;
    private $relay;
    private $gas;
    private $channels;
    private $verify;

    public function __construct(Config $cfg, Store $store, ?Chain $chain = null, ?Relay $relay = null)
    {
        $this->cfg = $cfg;
        $this->store = $store;
        $this->chain = $chain ?: new Chain($cfg->rpcUrl);
        $this->relay = $relay ?: new Relay($cfg->relayUrl);
        $this->gas = new GasQuote($cfg, $this->chain, $this->relay);
        $this->channels = new Channels($cfg, $store, $this->chain);
        $this->verify = new Verify($cfg, $this->chain);
    }

    public function gas()
    {
        return $this->gas;
    }

    public function micro($price)
    {
        if (is_array($price)) {
            $price = isset($price['micro']) ? $price['micro'] : null;
        }
        return $price !== null && is_numeric($price) && (float) $price >= 1 ? (int) $price : $this->cfg->priceMicroUSD;
    }

    public function accepts($price = null)
    {
        $micro = $this->micro($price);
        $rows = [];
        foreach ($this->cfg->assets as $asset) {
            foreach ($asset['eip3009'] ? ['eip3009', 'permit2'] : ['permit2'] as $method) {
                $rows[] = $this->requirement($asset, $method, $micro);
            }
        }
        $dollar = array_values(array_filter($rows, function ($r) {
            return isset($r['extra']['name']) && preg_match('/^USD Coin$/i', $r['extra']['name']);
        }));
        if ($dollar !== [] && count($rows) > count($dollar)) {
            $rows = array_merge($rows, $dollar);
        }
        return $rows;
    }

    public function requirement(array $asset, $method, $micro = null)
    {
        $extra = [];
        if ($method === 'eip3009') {
            if ($asset['name'] !== null && $asset['name'] !== '') {
                $extra['name'] = $asset['name'];
                $extra['version'] = $asset['version'] !== null ? $asset['version'] : '1';
            }
        } else {
            $extra['assetTransferMethod'] = 'permit2';
        }
        $extra['receiverAuthorizer'] = $this->cfg->receiverAuthorizer;
        $extra['withdrawDelay'] = $this->cfg->withdrawDelay;
        return [
            'scheme' => Config::SCHEME,
            'network' => $this->cfg->network,
            'amount' => $this->cfg->unitsOfMicroUSD($asset, $micro === null ? $this->cfg->priceMicroUSD : $micro),
            'asset' => $asset['address'],
            'payTo' => $this->cfg->receiver,
            'maxTimeoutSeconds' => $this->cfg->maxTimeoutSeconds,
            'extra' => $extra,
        ];
    }

    public static function resourceInfo($resource)
    {
        if (is_array($resource)) {
            $out = ['url' => isset($resource['url']) ? (string) $resource['url'] : ''];
            if (isset($resource['description']) && is_string($resource['description']) && $resource['description'] !== '') {
                $out['description'] = substr($resource['description'], 0, 300);
            }
            $out['mimeType'] = isset($resource['mimeType']) ? (string) $resource['mimeType'] : 'application/json';
            return $out;
        }
        return ['url' => (string) $resource, 'mimeType' => 'application/json'];
    }

    private static function document(array $resource, $error, array $accepts)
    {
        $doc = ['x402Version' => 2];
        if ($error !== null) {
            $doc['error'] = $error;
        }
        $doc['resource'] = $resource;
        $doc['accepts'] = $accepts;
        return $doc;
    }

    public static function percent($share)
    {
        return rtrim(rtrim(sprintf('%.2f', (float) $share * 100), '0'), '.');
    }

    private static function usd($microUSD)
    {
        $s = rtrim(rtrim(sprintf('%.6f', $microUSD / 1e6), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    private function priced($price)
    {
        return is_array($price) ? ['micro' => $this->micro($price), 'unitMicro' => isset($price['unitMicro']) ? $price['unitMicro'] : null] : ['micro' => $this->micro($price), 'unitMicro' => null];
    }

    public function terms($refundUrl = null, $price = null)
    {
        $p = $this->priced($price);
        $floor = $this->gas->depositFloorMicroUSD($p['micro']);
        $q = $this->gas->refund(false, false);
        $cover = $q === null ? null : $q['cover']['microUSD'];
        $relayed = $this->gas->relayRefundQuote();
        $gas = $relayed !== null ? $relayed['microUSD'] : ($q === null ? null : $q['pay']['microUSD']);
        $at = $this->cfg->refundUrl ? $this->cfg->refundUrl : $refundUrl;
        $where = $at ? 'POST ' . $at : 'POST /refund on this site';
        $about = function ($microUSD) {
            return $microUSD === null ? '' : ' ($' . self::usd($microUSD) . ' now)';
        };
        $margin = $relayed !== null ? $relayed['marginPercent'] : (int) round(($this->cfg->gasBuffer - 1) * 100);
        return [
            'pricing' => Pricing::pricing($p),
            'deposit' => 'First call on a channel deposits at least $' . self::usd($floor) . '. Later calls spend it. The unspent balance is refundable.',
            'refund' => 'Refund: ' . $where . ' with {channelId, issued, signature} signed by the payer; add channelConfig if the channel has no calls. ZEAM pays the gas when the channel\'s fees (' . self::percent($this->cfg->feeShare) . '% of its spend) cover its deposit and refund gas' . $about($cover) . '. Otherwise sign a gasless USDC payment of the quoted gas' . $about($gas) . ', ' . $margin . '% margin included. An unsettled call adds its claim gas. 1 refund per channel per hour. {"selfSend": true}: a signed refund you send at your own gas.',
        ];
    }

    public function paymentRequired($resource, ?array $extensions = null, $refundUrl = null, array $more = [], $price = null)
    {
        $p = $this->priced($price);
        $doc = self::document(self::resourceInfo($resource), null, $this->accepts($p['micro']));
        if ($extensions) {
            $doc['extensions'] = $extensions;
        }
        $doc = array_merge($doc, $this->terms($refundUrl, $p), $more);
        return ['ok' => false, 'status' => 402, 'body' => $doc, 'headers' => ['PAYMENT-REQUIRED' => Json::base64($doc)]];
    }

    private static function refuse($status, array $body, array $headers = [])
    {
        return ['ok' => false, 'status' => $status, 'body' => $body, 'headers' => $headers];
    }

    public static function channelIdOf(array $payload)
    {
        $raw = isset($payload['payload']) && is_array($payload['payload']) ? $payload['payload'] : [];
        $id = isset($raw['voucher']['channelId']) ? $raw['voucher']['channelId'] : (isset($raw['channelId']) ? $raw['channelId'] : null);
        return is_string($id) && preg_match('/^0x[0-9a-fA-F]{64}$/D', $id) ? strtolower($id) : null;
    }

    public function match(array $accepts, array $payload)
    {
        $version = isset($payload['x402Version']) ? $payload['x402Version'] : null;
        $accepted = isset($payload['accepted']) && is_array($payload['accepted']) ? $payload['accepted'] : null;
        if ($accepted === null) {
            return null;
        }
        foreach ($accepts as $req) {
            if ($version === 2) {
                $reqCore = $req;
                unset($reqCore['extra']);
                $accCore = $accepted;
                unset($accCore['extra']);
                if (Json::deepEqual($reqCore, $accCore) && Json::containsSubset($req['extra'], isset($accepted['extra']) ? $accepted['extra'] : null)) {
                    return $req;
                }
            } elseif ($version === 1) {
                if (isset($accepted['scheme'], $accepted['network']) && $req['scheme'] === $accepted['scheme'] && $req['network'] === $accepted['network']) {
                    return $req;
                }
            }
        }
        return null;
    }

    private static function provisional(array $raw, $charged, $now)
    {
        return [
            'channelId' => $raw['voucher']['channelId'],
            'channelConfig' => $raw['channelConfig'],
            'chargedCumulativeAmount' => (string) $charged,
            'signedMaxClaimable' => $raw['voucher']['maxClaimableAmount'],
            'signature' => $raw['voucher']['signature'],
            'balance' => '0',
            'totalClaimed' => '0',
            'withdrawRequestedAt' => 0,
            'refundNonce' => 0,
            'lastRequestTimestamp' => $now,
        ];
    }

    private static function inferCharged($signedMax, $price)
    {
        $signed = Verify::uint($signedMax);
        $amount = Verify::uint($price);
        return Big::cmp($signed, $amount) < 0 ? '0' : Big::strval(Big::sub($signed, $amount));
    }

    private static function channelState(array $c, $charged = null)
    {
        $out = [
            'channelId' => $c['channelId'],
            'balance' => $c['balance'],
            'totalClaimed' => $c['totalClaimed'],
            'withdrawRequestedAt' => $c['withdrawRequestedAt'],
            'refundNonce' => (string) $c['refundNonce'],
        ];
        if ($charged !== null) {
            $out['chargedCumulativeAmount'] = (string) $charged;
        }
        return $out;
    }

    public function enrich(array $accepts, $error, array $payload, $snapshot)
    {
        if ($error !== self::MISMATCH) {
            return $accepts;
        }
        $raw = isset($payload['payload']) ? $payload['payload'] : null;
        if (!Verify::isVoucherPayload($raw) && !Verify::isDepositPayload($raw) && !Verify::isRefundPayload($raw)) {
            return $accepts;
        }
        $network = isset($payload['accepted']['network']) ? $payload['accepted']['network'] : null;
        try {
            if (Verify::bindingError($raw['channelConfig'], $raw['voucher']['channelId'], $network)) {
                return $accepts;
            }
            $channel = $snapshot !== null ? $snapshot : $this->store->get(strtolower($raw['voucher']['channelId']));
        } catch (\Throwable $e) {
            return $accepts;
        }
        if ($channel === null) {
            return $accepts;
        }
        foreach ($accepts as $i => $req) {
            if ($req['scheme'] === Config::SCHEME && $req['network'] === $network) {
                $accepts[$i]['extra']['channelState'] = self::channelState($channel, $channel['chargedCumulativeAmount']);
                $accepts[$i]['extra']['voucherState'] = [
                    'signedMaxClaimable' => $channel['signedMaxClaimable'],
                    'signature' => $channel['signature'],
                ];
                break;
            }
        }
        return $accepts;
    }

    private static function stateIn(array $accepts)
    {
        foreach ($accepts as $a) {
            if (isset($a['extra']['channelState'])) {
                return $a['extra']['channelState'];
            }
        }
        return null;
    }

    private function fundingRefusal(array $payload, array $accepts, $floor, $price = null)
    {
        $price = $price === null ? $this->cfg->priceMicroUSD : $price;
        $quoted = $this->quotedMicroUSD($payload);
        if ($quoted + 1 < $price) {
            return [
                'error' => 'price_changed', 'code' => 'price_changed', 'quotedMicroUSD' => $quoted, 'neededMicroUSD' => $floor,
                'message' => "the price is $price micro-USD per call. Pay the accepts quote. Nothing was charged.",
                'accepts' => $accepts,
            ];
        }
        $deposit = $this->depositMicroUSD($payload);
        return [
            'error' => 'funding_requires_open_fee', 'code' => 'funding_requires_open_fee',
            'quotedMicroUSD' => $quoted, 'depositMicroUSD' => $deposit, 'neededMicroUSD' => $floor,
            'message' => "deposit $deposit micro-USD is under the $floor micro-USD floor. Deposit at least $floor micro-USD; each call costs $quoted micro-USD; the unspent balance is refundable. Nothing was charged.",
            'accepts' => $accepts,
        ];
    }

    private function quotedMicroUSD(array $payload)
    {
        try {
            $acc = isset($payload['accepted']) ? $payload['accepted'] : [];
            $v = $this->cfg->microUSDOf(isset($acc['asset']) ? $acc['asset'] : '', Big::strval(Verify::uint(isset($acc['amount']) ? $acc['amount'] : '0')));
            return $v === null ? 0 : $v;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function depositMicroUSD(array $payload)
    {
        try {
            $acc = isset($payload['accepted']) ? $payload['accepted'] : [];
            $amount = isset($payload['payload']['deposit']['amount']) ? $payload['payload']['deposit']['amount'] : '0';
            $v = $this->cfg->microUSDOf(isset($acc['asset']) ? $acc['asset'] : '', Big::strval(Verify::uint($amount)));
            return $v === null ? 0 : $v;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function verifyAndHold($paymentHeaderValue, $resource, $price = null)
    {
        $micro = $this->micro($price);
        $res = self::resourceInfo($resource);
        $accepts = $this->accepts($micro);
        $payload = Json::decodeHeader($paymentHeaderValue);
        if ($payload === null) {
            return self::refuse(400, ['error' => 'bad_payment_header', 'message' => 'send PAYMENT-SIGNATURE, or base64 of the payload in x-payment']);
        }
        $raw = isset($payload['payload']) && is_array($payload['payload']) ? $payload['payload'] : null;
        $kind = $raw !== null && isset($raw['type']) ? $raw['type'] : null;
        $batch = (isset($payload['scheme']) && $payload['scheme'] === Config::SCHEME)
            || (isset($payload['accepted']['scheme']) && $payload['accepted']['scheme'] === Config::SCHEME)
            || ($kind !== null && $kind !== '' && $kind !== false && $kind !== 0);
        if ($batch && $kind !== 'voucher' && $kind !== 'deposit') {
            return self::refuse(400, ['error' => 'bad_payment_type', 'message' => 'a paid call carries a voucher or a deposit. Refunds: POST /refund. Nothing was charged.']);
        }
        $cid = self::channelIdOf($payload);
        $ch = null;
        if ($cid !== null) {
            try {
                $ch = $this->channels->repaired($cid);
            } catch (\Throwable $e) {
                $ch = null;
            }
        }
        if ($ch !== null) {
            if ($this->channels->leaving($ch)) {
                return self::refuse(402, ['error' => 'channel_leaving', 'code' => 'channel_leaving', 'message' => 'this channel is withdrawing. Open a new channel. Nothing was charged.']);
            }
            if (!empty($ch['handedOver'])) {
                return self::refuse(402, [
                    'error' => 'refund_outstanding', 'code' => 'refund_outstanding',
                    'transaction' => isset($ch['handedOver']['transaction']) ? $ch['handedOver']['transaction'] : null,
                    'message' => 'this channel has an unsent signed refund. Send it (transaction here), then deposit again. Nothing was charged.',
                ]);
            }
            if ($kind === 'deposit') {
                $left = Big::init(0);
                $price = Big::init(0);
                try {
                    $left = Big::sub(Channels::n($ch['balance']), Channels::max($ch['chargedCumulativeAmount'], $ch['totalClaimed']));
                    $price = Verify::uint(isset($payload['accepted']['amount']) ? $payload['accepted']['amount'] : '0');
                } catch (\Throwable $e) {
                    $price = Big::init(0);
                }
                if (Big::sign($price) > 0 && Big::cmp($left, $price) >= 0) {
                    $enriched = $this->enrich($accepts, self::MISMATCH, $payload, null);
                    if (self::stateIn($enriched) !== null) {
                        $corrected = self::document($res, self::MISMATCH, $enriched);
                        $body = $corrected;
                        $body['message'] = 'this channel is funded. Pay with a voucher. Nothing was charged.';
                        return self::refuse(402, $body, ['PAYMENT-REQUIRED' => Json::base64($corrected)]);
                    }
                }
            }
        }
        if ($kind === 'deposit') {
            $floor = $this->gas->depositFloorMicroUSD($micro);
            if ($this->quotedMicroUSD($payload) + 1 < $micro || $this->depositMicroUSD($payload) + 1 < $floor) {
                return self::refuse(402, $this->fundingRefusal($payload, $accepts, $floor, $micro));
            }
        }
        $match = $this->match($accepts, $payload);
        if ($match === null) {
            return self::refuse(402, ['error' => 'no_matching_requirement', 'message' => 'the payment matches no requirement here', 'accepts' => $accepts]);
        }
        $ctx = ['snapshot' => null];
        $verified = $this->verifyPayment($payload, $match, $ctx);
        if (!$verified['isValid']) {
            $reason = isset($verified['invalidReason']) ? $verified['invalidReason'] : null;
            $error = preg_match('/exceeds_balance|below_claimed/', (string) $reason) ? self::MISMATCH : $reason;
            $enriched = $this->enrich($accepts, $error, $payload, $ctx['snapshot']);
            $corrected = self::document($res, $error, $enriched);
            $body = ['error' => $error !== null ? $error : 'payment_invalid', 'code' => 'payment_invalid', 'message' => 'the payment did not verify'];
            if ($reason !== null) {
                $body['reason'] = $reason;
            }
            $body['accepts'] = $enriched;
            $state = self::stateIn($enriched);
            if ($state !== null) {
                $body['channelState'] = $state;
            }
            return self::refuse(402, $body, ['PAYMENT-REQUIRED' => Json::base64($corrected)]);
        }
        return [
            'ok' => true,
            'hold' => [
                'kind' => $kind,
                'channelId' => $ctx['channelId'],
                'pendingId' => $ctx['pendingId'],
                'payload' => $payload,
                'requirement' => $match,
                'resource' => $res,
                'payer' => $verified['payer'],
                'micro' => $micro,
            ],
        ];
    }

    public function verifyPayment(array $payload, array $req, array &$ctx)
    {
        $raw = $payload['payload'];
        $local = null;
        $now = $this->cfg->nowMs();
        if (Verify::isVoucherPayload($raw) || Verify::isDepositPayload($raw)) {
            try {
                $bind = Verify::bindingError($raw['channelConfig'], $raw['voucher']['channelId'], $req['network']);
                if ($bind) {
                    return Verify::invalid($bind);
                }
                $cid = strtolower($raw['voucher']['channelId']);
                $snapshot = $this->store->get($cid);
                $charged = $snapshot !== null && isset($snapshot['chargedCumulativeAmount'])
                    ? (string) $snapshot['chargedCumulativeAmount']
                    : self::inferCharged($raw['voucher']['maxClaimableAmount'], $req['amount']);
                $expected = Big::add(Big::init($charged, 10), Verify::uint($req['amount']));
                if (Big::cmp(Verify::uint($raw['voucher']['maxClaimableAmount']), $expected) !== 0) {
                    $ctx['snapshot'] = $snapshot !== null ? $snapshot : self::provisional($raw, $charged, $now);
                    return Verify::invalid(self::MISMATCH);
                }
                $ctx['snapshot'] = $snapshot;
                $ctx['channelId'] = $cid;
                $ctx['pendingId'] = Hex::fromBin(random_bytes(32));
                if (Verify::isVoucherPayload($raw)) {
                    $local = $this->verify->local($raw, $req, $snapshot, $now);
                }
            } catch (\Throwable $e) {
                return Verify::invalid(Reason::VERIFICATION_STATE_UNAVAILABLE);
            }
        }
        $result = $local !== null ? $local : $this->verify->facilitator($payload, $req);
        if (!$result['isValid'] || empty($result['payer'])) {
            return $result;
        }
        if (!isset($ctx['pendingId'])) {
            return Verify::invalid(Reason::VERIFICATION_STATE_UNAVAILABLE);
        }
        $ex = isset($result['extra']) ? $result['extra'] : [];
        $record = null;
        $outcome = null;
        $pendingId = $ctx['pendingId'];
        $expiresAt = $now + min(self::MAX_PENDING_TTL_MS, max(self::MIN_PENDING_TTL_MS, max(0, (int) $req['maxTimeoutSeconds']) * 1000));
        $isLocal = $local !== null;
        try {
            $this->store->update($ctx['channelId'], function ($current) use ($raw, $req, $ex, $now, $pendingId, $expiresAt, $isLocal, &$outcome, &$record) {
                if (Channels::pendingLive($current, $now)) {
                    $outcome = 'busy';
                    return $current;
                }
                $base = $current !== null && isset($current['chargedCumulativeAmount'])
                    ? (string) $current['chargedCumulativeAmount']
                    : self::inferCharged($raw['voucher']['maxClaimableAmount'], $req['amount']);
                $expected = Big::add(Big::init($base, 10), Verify::uint($req['amount']));
                if (Big::cmp(Verify::uint($raw['voucher']['maxClaimableAmount']), $expected) !== 0) {
                    $outcome = 'stale';
                    $record = $current !== null ? $current : self::provisional($raw, $base, $now);
                    return $current;
                }
                $next = [
                    'channelId' => $raw['voucher']['channelId'],
                    'channelConfig' => $raw['channelConfig'],
                    'chargedCumulativeAmount' => $base,
                    'signedMaxClaimable' => $raw['voucher']['maxClaimableAmount'],
                    'signature' => $raw['voucher']['signature'],
                    'balance' => isset($ex['balance']) ? (string) $ex['balance'] : '0',
                    'totalClaimed' => isset($ex['totalClaimed']) ? (string) $ex['totalClaimed'] : '0',
                    'withdrawRequestedAt' => isset($ex['withdrawRequestedAt']) ? (int) $ex['withdrawRequestedAt'] : 0,
                    'refundNonce' => isset($ex['refundNonce']) ? (int) $ex['refundNonce'] : 0,
                ];
                $synced = $isLocal ? ($current !== null && isset($current['onchainSyncedAt']) ? $current['onchainSyncedAt'] : null) : $now;
                if ($synced !== null) {
                    $next['onchainSyncedAt'] = $synced;
                }
                $next['lastRequestTimestamp'] = $now;
                $next['pendingRequest'] = [
                    'pendingId' => $pendingId,
                    'signedMaxClaimable' => $raw['voucher']['maxClaimableAmount'],
                    'expiresAt' => $expiresAt,
                ];
                foreach ($current !== null ? $current : [] as $k => $v) {
                    if (!in_array($k, self::SDK_FIELDS, true)) {
                        $next[$k] = $v;
                    }
                }
                $outcome = 'reserved';
                $record = $next;
                return $next;
            });
        } catch (\Throwable $e) {
            return Verify::invalid(Reason::VERIFICATION_STATE_UNAVAILABLE);
        }
        if ($outcome === 'busy') {
            return Verify::invalid(Reason::CHANNEL_BUSY);
        }
        if ($outcome === 'stale') {
            $ctx['snapshot'] = $record;
            return Verify::invalid(self::MISMATCH);
        }
        $ctx['snapshot'] = $record;
        return $result;
    }

    public function release(array $hold)
    {
        return $this->channels->release($hold['channelId'], $hold['pendingId']);
    }

    private function settleFailed(array $hold, $reason)
    {
        $this->release($hold);
        $accepts = $this->accepts(isset($hold['micro']) ? $hold['micro'] : null);
        $doc = self::document($hold['resource'], 'settle_failed', $accepts);
        return self::refuse(402, [
            'error' => 'settle_failed', 'code' => 'settle_failed',
            'message' => 'the payment did not settle. Not delivered; nothing was charged.',
            'reason' => substr((string) $reason, 0, 200),
            'accepts' => $accepts,
        ], ['PAYMENT-REQUIRED' => Json::base64($doc)]);
    }

    private static function delivered(array $response)
    {
        return ['ok' => true, 'status' => 200, 'response' => $response, 'headers' => ['PAYMENT-RESPONSE' => Json::base64($response)]];
    }

    public static function chargeOf(array $hold, $charge)
    {
        $reserved = Verify::uint($hold['requirement']['amount']);
        if ($charge === null) {
            return $reserved;
        }
        $text = is_int($charge) ? (string) $charge : (is_string($charge) ? trim($charge) : '');
        if (!preg_match('/^-?[0-9]+$/D', $text)) {
            throw new \RangeException(esc_html('a charge of ' . (is_scalar($charge) ? (string) $charge : 'that') . ' is outside 0 to the reserved ' . Big::strval($reserved)));
        }
        $c = Big::init($text, 10);
        if (Big::sign($c) < 0 || Big::cmp($c, $reserved) > 0) {
            throw new \RangeException(esc_html('a charge of ' . Big::strval($c) . ' is outside 0 to the reserved ' . Big::strval($reserved)));
        }
        return $c;
    }

    public static function chargedExtra(array $hold, $charged, array $extra = [])
    {
        $reserved = (string) $hold['requirement']['amount'];
        $extra['chargedAmount'] = Big::strval($charged);
        $extra['reservedAmount'] = $reserved;
        return $extra;
    }

    public function settle(array $hold, $charge = null)
    {
        try {
            $c = self::chargeOf($hold, $charge);
            if ($hold['kind'] === 'voucher') {
                return $this->settleVoucher($hold, $c);
            }
            if ($hold['kind'] === 'deposit') {
                return $this->settleDeposit($hold, $c);
            }
            return $this->settleFailed($hold, Reason::PAYLOAD_TYPE);
        } catch (\Throwable $e) {
            return $this->settleFailed($hold, \ZeamPass\message($e));
        }
    }

    public function settleVoucher(array $hold, $increment = null)
    {
        $req = $hold['requirement'];
        $voucher = $hold['payload']['payload']['voucher'];
        $increment = $increment === null ? Verify::uint($req['amount']) : $increment;
        $cap = Verify::uint($voucher['maxClaimableAmount']);
        $pendingId = $hold['pendingId'];
        $now = $this->cfg->nowMs();
        $outcome = null;
        $previous = null;
        $charged = null;
        $this->store->update($hold['channelId'], function ($current) use ($pendingId, $increment, $cap, $voucher, $now, &$outcome, &$previous, &$charged) {
            if ($current === null) {
                $outcome = 'missing';
                return $current;
            }
            if (!isset($current['pendingRequest']['pendingId']) || $current['pendingRequest']['pendingId'] !== $pendingId) {
                $outcome = 'pending_mismatch';
                return $current;
            }
            $next = Big::add(Big::init((string) $current['chargedCumulativeAmount'], 10), $increment);
            if (Big::cmp($next, $cap) > 0) {
                $outcome = 'cap_exceeded';
                unset($current['pendingRequest']);
                return $current;
            }
            $previous = $current;
            $charged = Big::strval($next);
            $current['chargedCumulativeAmount'] = $charged;
            $current['signedMaxClaimable'] = $voucher['maxClaimableAmount'];
            $current['signature'] = $voucher['signature'];
            $current['lastRequestTimestamp'] = $now;
            unset($current['pendingRequest']);
            $outcome = 'committed';
            return $current;
        });
        if ($outcome === 'missing') {
            return $this->settleFailed($hold, Reason::MISSING_CHANNEL);
        }
        if ($outcome === 'cap_exceeded') {
            return $this->settleFailed($hold, Reason::CHARGE_EXCEEDS_SIGNED_CUMULATIVE);
        }
        if ($outcome !== 'committed') {
            return $this->settleFailed($hold, Reason::CHANNEL_BUSY);
        }
        return self::delivered([
            'success' => true,
            'payer' => strtolower($previous['channelConfig']['payer']),
            'transaction' => '',
            'network' => $req['network'],
            'amount' => '',
            'extra' => self::chargedExtra($hold, $increment, ['channelState' => self::channelState($previous, $charged)]),
        ]);
    }

    public function settleDeposit(array $hold, $increment = null)
    {
        $req = $hold['requirement'];
        $payload = $hold['payload'];
        $raw = $payload['payload'];
        $channelId = $hold['channelId'];
        $increment = $increment === null ? Verify::uint($req['amount']) : $increment;
        $micro = $this->micro(isset($hold['micro']) ? $hold['micro'] : null);
        $this->channels->repaired($channelId);
        $floor = $this->gas->depositFloorMicroUSD($micro);
        $quoted = (int) $this->cfg->microUSDOf($req['asset'], $req['amount']);
        $arriving = (int) $this->cfg->microUSDOf($req['asset'], Big::strval(Verify::uint($raw['deposit']['amount'])));
        if ($quoted + 1 < $micro || $arriving + 1 < $floor) {
            return $this->settleFailed($hold, "every deposit is at least $floor micro-USD. Pay the accepts funding row with at least that.");
        }
        $verified = $this->verify->facilitator($payload, $req);
        if (!$verified['isValid']) {
            return $this->settleFailed($hold, isset($verified['invalidReason']) ? $verified['invalidReason'] : Reason::PAYLOAD_TYPE);
        }
        $execution = $verified['execution'];
        $amount = Big::strval(Verify::uint($raw['deposit']['amount']));
        $data = BatchSettlement::encodeDeposit($raw['channelConfig'], $amount, $execution['collector'], $execution['collectorData']);
        $sent = $this->relay->send([['to' => BatchSettlement::ESCROW, 'data' => $data]], $this->cfg->relaySplit());
        if (!isset($sent[0]['hash'])) {
            return $this->settleFailed($hold, Reason::DEPOSIT_TRANSACTION_FAILED . ': ' . (isset($sent[0]['error']) ? $sent[0]['error'] : 'no hash'));
        }
        $hash = $sent[0]['hash'];
        $receipt = $this->waitForReceipt($hash);
        if ($receipt === null) {
            return $this->settleFailed($hold, Reason::DEPOSIT_TRANSACTION_FAILED . ": no receipt for $hash");
        }
        if (!isset($receipt['status']) || strtolower((string) $receipt['status']) !== '0x1') {
            return $this->settleFailed($hold, Reason::DEPOSIT_TRANSACTION_FAILED . ': transaction reverted (receipt status reverted)');
        }
        $ex = $verified['extra'];
        $state = [
            'channelId' => $raw['voucher']['channelId'],
            'balance' => Big::strval(Big::add(Big::init((string) $ex['balance'], 10), Big::init($amount, 10))),
            'totalClaimed' => (string) $ex['totalClaimed'],
            'withdrawRequestedAt' => (int) $ex['withdrawRequestedAt'],
            'refundNonce' => (string) $ex['refundNonce'],
        ];
        $expected = Big::init($state['balance'], 10);
        $deadline = $this->cfg->nowMs() + $this->cfg->stateCatchUpMs;
        $post = null;
        while (true) {
            try {
                $post = $this->chain->state($channelId);
            } catch (\Throwable $e) {
                $post = null;
            }
            if ($post !== null && Big::cmp(Big::init($post['balance'], 10), $expected) >= 0) {
                break;
            }
            if ($this->cfg->nowMs() >= $deadline) {
                $post = null;
                break;
            }
            $this->cfg->sleepMs(150);
        }
        if ($post !== null) {
            $state = [
                'channelId' => $raw['voucher']['channelId'],
                'balance' => $post['balance'],
                'totalClaimed' => $post['totalClaimed'],
                'withdrawRequestedAt' => $post['withdrawRequestedAt'],
                'refundNonce' => $post['refundNonce'],
            ];
        }
        $pendingId = $hold['pendingId'];
        $now = $this->cfg->nowMs();
        $record = null;
        $this->store->update($channelId, function ($current) use ($raw, $increment, $state, $pendingId, $now, &$record) {
            if ($current === null || !isset($current['pendingRequest']['pendingId']) || $current['pendingRequest']['pendingId'] !== $pendingId) {
                return $current;
            }
            $next = [
                'channelId' => $raw['voucher']['channelId'],
                'channelConfig' => $raw['channelConfig'],
                'chargedCumulativeAmount' => Big::strval(Big::add(Big::init((string) $current['chargedCumulativeAmount'], 10), $increment)),
                'signedMaxClaimable' => $raw['voucher']['maxClaimableAmount'],
                'signature' => $raw['voucher']['signature'],
                'balance' => $state['balance'],
                'totalClaimed' => $state['totalClaimed'],
                'withdrawRequestedAt' => (int) $state['withdrawRequestedAt'],
                'refundNonce' => (int) $state['refundNonce'],
                'onchainSyncedAt' => $now,
                'lastRequestTimestamp' => $now,
            ];
            foreach ($current as $k => $v) {
                if (!in_array($k, self::SDK_FIELDS, true)) {
                    $next[$k] = $v;
                }
            }
            $next['depositsWePaid'] = (isset($current['depositsWePaid']) ? (int) $current['depositsWePaid'] : 0) + 1;
            $record = $next;
            return $next;
        });
        if ($record === null) {
            return $this->settleFailed($hold, Reason::CHANNEL_BUSY . ": deposit {$hash} landed after the hold expired. Not charged, not delivered.");
        }
        $channelState = $state;
        $channelState['chargedCumulativeAmount'] = $record['chargedCumulativeAmount'];
        $extra = self::chargedExtra($hold, $increment, ['channelState' => $channelState]);
        $response = [
            'success' => true,
            'transaction' => $hash,
            'network' => $req['network'],
            'payer' => $raw['channelConfig']['payer'],
            'amount' => $raw['deposit']['amount'],
            'extra' => $extra,
        ];
        if ((string) $response['amount'] !== (string) $req['amount']) {
            $response['extra']['depositAmount'] = $response['amount'];
            $response['amount'] = (string) $req['amount'];
        }
        return self::delivered($response);
    }

    private function waitForReceipt($hash)
    {
        $deadline = $this->cfg->nowMs() + 1000 * $this->cfg->receiptTimeoutSecs;
        while (true) {
            try {
                $r = $this->chain->receipt($hash);
            } catch (\Throwable $e) {
                $r = null;
            }
            if ($r !== null) {
                return $r;
            }
            if ($this->cfg->nowMs() >= $deadline) {
                return null;
            }
            $this->cfg->sleepMs($this->cfg->receiptPollMs);
        }
    }
}
