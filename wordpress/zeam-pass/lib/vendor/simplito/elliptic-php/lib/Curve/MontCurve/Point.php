<?php

namespace Elliptic\Curve\MontCurve;

use BN\BN;

class Point extends \Elliptic\Curve\BaseCurve\Point
{
    public $x;
    public $z;

    function __construct($curve, $x, $z)
    {
        parent::__construct($curve, "projective");
        if( $x == null && $z == null )
        {
            $this->x = $this->curve->one;
            $this->z = $this->curve->zero;
        }
        else
        {
            $this->x = new BN($x, 16);
            $this->z = new BN($z, 16);
            if( !$this->x->red )
                $this->x = $this->x->toRed($this->curve->red);
            if( !$this->z->red )
                $this->z = $this->z->toRed($this->curve->red);
        }
    }

    public function precompute($power = null) {
    }

    protected function _encode($compact) {
        return $this->getX()->toArray("be", $this->curve->p->byteLength());
    }

    public static function fromJSON($curve, $obj) {
        return new Point($curve, $obj[0], isset($obj[1]) ? $obj[1] : $curve->one);
    }

    public function inspect()
    {
        if( $this->isInfinity() )
            return "<EC Point Infinity>";
        return "<EC Point x: " . $this->x->fromRed()->toString(16, 2) .
            " z: " . $this->z->fromRed()->toString(16, 2) . ">";
    }

    public function isInfinity() {
        return $this->z->isZero();
    }

    public function dbl()
    {
        $a = $this->x->redAdd($this->z);

        $aa = $a->redSqr();

        $b = $this->x->redSub($this->z);

        $bb = $b->redSqr();

        $c = $aa->redSub($bb);

        $nx = $aa->redMul($bb);

        $nz = $c->redMul( $bb->redAdd($this->curve->a24->redMul($c)) );
        return $this->curve->point($nx, $nz);
    }

    public function add($p) {
        throw new \Exception('Not supported on Montgomery curve');
    }

    public function diffAdd($p, $diff)
    {
        $a = $this->x->redAdd($this->z);

        $b = $this->x->redSub($this->z);

        $c = $p->x->redAdd($p->z);

        $d = $p->x->redSub($p->z);

        $da = $d->redMul($a);

        $cb = $c->redMul($b);

        $nx = $diff->z->redMul($da->redAdd($cb)->redSqr());

        $nz = $diff->x->redMul($da->redSub($cb)->redSqr());

        return $this->curve->point($nx, $nz);
    }

    public function mul($k)
    {
        $t = $k->_clone();
        $a = $this;
        $b = $this->curve->point(null, null);
        $c = $this;

        $bits = array();
        while( !$t->isZero() )
        {
            array_push($bits, $t->andln(1));
            $t->iushrn(1);
        }

        for($i = count($bits) - 1; $i >= 0; $i--)
        {
            if( $bits[$i] === 0 )
            {
                $a = $a->diffAdd($b, $c);

                $b = $b->dbl();
            }
            else
            {
                $b = $a->diffAdd($b, $c);

                $a = $a->dbl();
            }
        }

        return $b;
    }

    public function eq($other) {
        return $this->getX()->cmp($other->getX()) === 0;
    }

    public function normalize()
    {
        $this->x = $this->x->redMul($this->z->redInvm());
        $this->z = $this->curve->one;
        return $this;
    }

    public function getX() {
        $this->normalize();
        return $this->x->fromRed();
    }
}

?>
