<?php

namespace ZeamPass;

final class Pass
{
    const REALM = 'ZEAM Pass';

    public static function grantMessage($realm, array $grant)
    {
        $scope = array_key_exists('scope', $grant) && $grant['scope'] !== null ? $grant['scope'] : 'self';
        $message = $realm . " grant\ndelegate: " . strtolower((string) $grant['delegate'])
            . "\nscope: " . strtolower((string) $scope)
            . "\nuntil: " . $grant['until'];
        if (isset($grant['budget']) && $grant['budget'] !== '') {
            $message .= "\nbudget: " . $grant['budget'];
        }
        return $message;
    }

    public static function grantId($message)
    {
        return Keccak::utf8($message);
    }

    public static function budgetMs($budget)
    {
        if ($budget === null || $budget === '') {
            return null;
        }
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*(ms|s|m|h|d)?$/', trim((string) $budget), $m)) {
            return false;
        }
        $units = ['ms' => 1, 's' => 1000, 'm' => 60000, 'h' => 3600000, 'd' => 86400000];
        $unit = isset($m[2]) && $m[2] !== '' ? $units[$m[2]] : 1;
        return (int) floor(((float) $m[1]) * $unit);
    }

    public static function signGrant($key, $realm, array $grant)
    {
        return Secp256k1::personalSign($key, self::grantMessage($realm, $grant));
    }

    public static function verifyGrant($realm, $grant, $nowMs = null)
    {
        if (!is_array($grant)) {
            return ['ok' => false, 'why' => 'no grant'];
        }
        if (empty($grant['delegate']) || empty($grant['until']) || empty($grant['signature'])) {
            return ['ok' => false, 'why' => 'a grant needs delegate, until and signature'];
        }
        $budget = isset($grant['budget']) ? $grant['budget'] : null;
        $ms = self::budgetMs($budget);
        if ($ms === false) {
            return ['ok' => false, 'why' => 'budget is not a duration (250ms, 30s, 2h, 1d)'];
        }
        $t = strtotime((string) $grant['until']);
        if ($t === false) {
            return ['ok' => false, 'why' => 'until is not a timestamp'];
        }
        $now = $nowMs === null ? (int) floor(microtime(true) * 1000) : (int) $nowMs;
        if ($t * 1000 <= $now) {
            return ['ok' => false, 'why' => 'grant expired at ' . $grant['until']];
        }
        $scope = isset($grant['scope']) ? $grant['scope'] : 'self';
        $message = self::grantMessage($realm, ['delegate' => $grant['delegate'], 'scope' => $scope, 'until' => $grant['until'], 'budget' => $budget]);
        try {
            $root = strtolower(Secp256k1::recoverPersonal($message, $grant['signature']));
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'why' => 'bad signature: ' . substr(\ZeamPass\message($e), 0, 80)];
        }
        return [
            'ok' => true,
            'root' => $root,
            'delegate' => strtolower((string) $grant['delegate']),
            'scope' => strtolower((string) $scope),
            'until' => $grant['until'],
            'budget' => $budget,
            'budgetMs' => $ms,
            'message' => $message,
            'id' => self::grantId($message),
        ];
    }

    public static function noteMessage(array $note)
    {
        return "ZEAM Pass gate credit\nid: " . $note['id']
            . "\nseller: " . strtolower((string) $note['seller'])
            . "\nname: " . $note['name']
            . "\nchecks: " . $note['checks']
            . "\nissued: " . $note['issued'];
    }

    public static function signNote($key, array $note)
    {
        return Secp256k1::personalSign($key, self::noteMessage($note));
    }

    public static function verifyNote($note, $issuer)
    {
        if (!is_array($note) || empty($note['id']) || empty($note['signature']) || !(is_numeric(isset($note['checks']) ? $note['checks'] : null) && $note['checks'] > 0)) {
            return ['ok' => false, 'why' => 'not a credit note'];
        }
        return Secp256k1::verifyPersonal($issuer, self::noteMessage($note), $note['signature'])
            ? ['ok' => true, 'checks' => (int) $note['checks']]
            : ['ok' => false, 'why' => 'the note is not signed by ZEAM'];
    }

    public static function sellerSplit($payout, $fee, $name, $whole = false)
    {
        if ($whole && Address::checksum($payout) !== Address::checksum($fee)) {
            throw new \InvalidArgumentException('payoutIsFeeRecipient needs the payout to be the fee recipient');
        }
        $split = $whole ? Splits::wholeSplit($fee) : Splits::feeSplit($payout, $fee);
        $salt = Splits::saltFor(self::REALM, $name);
        return [
            'split' => $split,
            'salt' => $salt,
            'owner' => Address::ZERO,
            'address' => Splits::predictAddress($split, Address::ZERO, $salt),
        ];
    }
}
