<?php
namespace Elliptic;

use Elliptic\EdDSA\KeyPair;
use Elliptic\EdDSA\Signature;

class EdDSA {
    public $curve;
    public $g;
    public $pointClass;
    public $encodingLength;
    public $hash;

    function __construct($curve) {
        assert($curve == "ed25519");

        $curve = \Elliptic\Curves::getCurve($curve)->curve;
        $this->curve = $curve;
        $this->g = $curve->g;
        $this->g->precompute($curve->n->bitLength() + 1);

        $this->pointClass = get_class($curve->point());
        $this->encodingLength = intval(ceil($curve->n->bitLength() / 8));

        $this->hash = [ "algo" => "sha512" ];
    }

    public function sign($message, #[\SensitiveParameter]
						 $secret) {
        $message = Utils::parseBytes($message);
        $key = $this->keyFromSecret($secret);
        $r = $this->hashInt($key->messagePrefix(), $message);
        $R = $this->g->mul($r);
        $Rencoded = $this->encodePoint($R);
        $s_ = $this->hashInt($Rencoded, $key->pubBytes(), $message)
            ->mul($key->priv());
        $S = $r->add($s_)->umod($this->curve->n);
        return $this->makeSignature([ "R" => $R, "S" => $S, "Rencoded" => $Rencoded ]);
    }

    public function verify($message, $sig, $pub) {
        $message = Utils::parseBytes($message);
        $sig = $this->makeSignature($sig);
        $key = $this->keyFromPublic($pub);
        $h = $this->hashInt($sig->Rencoded(), $key->pubBytes(), $message);
        $SG = $this->g->mul($sig->S());
        $RplusAh = $sig->R()->add($key->pub()->mul($h));
        return $RplusAh->eq($SG);
    }

    public function hashInt() {
        $arguments = func_get_args();

        $hash = hash_init($this->hash["algo"]);
        for ($i = 0; $i < count($arguments); $i++)
            hash_update($hash, Utils::toBin($arguments[$i]));
        return Utils::intFromLE(hash_final($hash))->umod($this->curve->n);
    }

    public function keyFromPublic($pub) {
        return KeyPair::fromPublic($this, $pub);
    }

    public function keyFromSecret(#[\SensitiveParameter]
		$secret) {
        return KeyPair::fromSecret($this, $secret);
    }

    public function makeSignature($sig) {
        if ($sig instanceof Signature)
            return $sig;
        return new Signature($this, $sig);
    }

    public function encodePoint($point) {
        $enc = $point->getY()->toArray('le', $this->encodingLength);
        $enc[$this->encodingLength - 1] |= $point->getX()->isOdd() ? 0x80 : 0;
        return $enc;
    }

    public function decodePoint($bytes) {
        $bytes = Utils::parseBytes($bytes);

        $lastIx = count($bytes) - 1;
        $normed = $bytes;
        $normed[$lastIx] = $bytes[$lastIx] & ~0x80;
        $xIsOdd = ($bytes[$lastIx] & 0x80) !== 0;

        $y = Utils::intFromLE($normed);
        return $this->curve->pointFromY($y, $xIsOdd);
    }

    public function encodeInt($num) {
        return $num->toArray('le', $this->encodingLength);
    }

    public function decodeInt($bytes) {
        return Utils::intFromLE($bytes);
    }

    public function isPoint($val) {
        return is_a($val, $this->pointClass);
    }
}
