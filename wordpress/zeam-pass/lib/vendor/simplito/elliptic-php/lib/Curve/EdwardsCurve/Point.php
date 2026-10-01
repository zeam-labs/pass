<?php
namespace Elliptic\Curve\EdwardsCurve;

use BN\BN;

class Point extends \Elliptic\Curve\BaseCurve\Point
{
    public $x;
    public $y;
    public $z;
    public $t;
    public $zOne;

    function __construct($curve, $x = null, $y = null, $z = null, $t = null) {
        parent::__construct($curve, 'projective');
        if ($x == null && $y == null && $z == null) {
            $this->x = $this->curve->zero;
            $this->y = $this->curve->one;
            $this->z = $this->curve->one;
            $this->t = $this->curve->zero;
            $this->zOne = true;
        } else {
            $this->x = new BN($x, 16);
            $this->y = new BN($y, 16);
            $this->z = $z ? new BN($z, 16) : $this->curve->one;
            $this->t = $t ? new BN($t, 16) : null;
            if (!$this->x->red)
                $this->x = $this->x->toRed($this->curve->red);
            if (!$this->y->red)
                $this->y = $this->y->toRed($this->curve->red);
            if (!$this->z->red)
                $this->z = $this->z->toRed($this->curve->red);
            if ($this->t && !$this->t->red)
                $this->t = $this->t->toRed($this->curve->red);
            $this->zOne = $this->z == $this->curve->one;

            if ($this->curve->extended && !$this->t) {
                $this->t = $this->x->redMul($this->y);
                if (!$this->zOne)
                    $this->t = $this->t->redMul($this->z->redInvm());
            }
        }
    }

    public static function fromJSON($curve, $obj) {
        return new Point($curve,
            isset($obj[0]) ? $obj[0] : null,
            isset($obj[1]) ? $obj[1] : null,
            isset($obj[2]) ? $obj[2] : null
            );
    }

    public function inspect() {
        if ($this->isInfinity())
            return '<EC Point Infinity>';
        return '<EC Point x: ' . $this->x->fromRed()->toString(16, 2) .
            ' y: ' . $this->y->fromRed()->toString(16, 2) .
            ' z: ' . $this->z->fromRed()->toString(16, 2) . '>';
    }

    public function isInfinity() {
        return $this->x->cmpn(0) == 0 &&
            $this->y->cmp($this->z) == 0;
    }

    public function _extDbl() {
        $a = $this->x->redSqr();

        $b = $this->y->redSqr();

        $c = $this->z->redSqr();
        $c = $c->redIAdd($c);

        $d = $this->curve->_mulA($a);

        $e = $this->x->redAdd($this->y)->redSqr()->redISub($a)->redISub($b);

        $g = $d->redAdd($b);

        $f = $g->redSub($c);

        $h = $d->redSub($b);

        $nx = $e->redMul($f);

        $ny = $g->redMul($h);

        $nt = $e->redMul($h);

        $nz = $f->redMul($g);
        return $this->curve->point($nx, $ny, $nz, $nt);
    }

    public function _projDbl() {
        $b = $this->x->redAdd($this->y)->redSqr();

        $c = $this->x->redSqr();

        $d = $this->y->redSqr();

        if ($this->curve->twisted) {
            $e = $this->curve->_mulA($c);

            $f = $e->redAdd($d);
            if ($this->zOne) {
                $nx = $b->redSub($c)->redSub($d)->redMul($f->redSub($this->curve->two));

                $ny = $f->redMul($e->redSub($d));

                $nz = $f->redSqr()->redSub($f)->redSub($f);
            } else {
                $h = $this->z->redSqr();

                $j = $f->redSub($h)->redISub($h);

                $nx = $b->redSub($c)->redISub($d)->redMul($j);

                $ny = $f->redMul($e->redSub($d));

                $nz = $f->redMul($j);
            }
        } else {
            $e = $c->redAdd($d);

            $h = $this->curve->_mulC($this->c->redMul($this->z))->redSqr();

            $j = $e->redSub($h)->redSub($h);

            $nx = $this->curve->_mulC($b->redISub($e))->redMul($j);

            $ny = $this->curve->_mulC($e)->redMul($c->redISub($d));

            $nz = $e->redMul($j);
        }
        return $this->curve->point($nx, $ny, $nz);
    }

    public function dbl() {
        if ($this->isInfinity())
            return $this;

        if ($this->curve->extended)
            return $this->_extDbl();
        else
            return $this->_projDbl();
    }

    public function _extAdd($p) {
        $a = $this->y->redSub($this->x)->redMul($p->y->redSub($p->x));

        $b = $this->y->redAdd($this->x)->redMul($p->y->redAdd($p->x));

        $c = $this->t->redMul($this->curve->dd)->redMul($p->t);

        $d = $this->z->redMul($p->z->redAdd($p->z));

        $e = $b->redSub($a);

        $f = $d->redSub($c);

        $g = $d->redAdd($c);

        $h = $b->redAdd($a);

        $nx = $e->redMul($f);

        $ny = $g->redMul($h);

        $nt = $e->redMul($h);

        $nz = $f->redMul($g);
        return $this->curve->point($nx, $ny, $nz, $nt);
    }

    public function _projAdd($p) {
        $a = $this->z->redMul($p->z);

        $b = $a->redSqr();

        $c = $this->x->redMul($p->x);

        $d = $this->y->redMul($p->y);

        $e = $this->curve->d->redMul($c)->redMul($d);

        $f = $b->redSub($e);

        $g = $b->redAdd($e);

        $tmp = $this->x->redAdd($this->y)->redMul($p->x->redAdd($p->y))->redISub($c)->redISub($d);
        $nx = $a->redMul($f)->redMul($tmp);
        if ($this->curve->twisted) {
            $ny = $a->redMul($g)->redMul($d->redSub($this->curve->_mulA($c)));

            $nz = $f->redMul($g);
        } else {
            $ny = $a->redMul($g)->redMul($d->redSub($c));

            $nz = $this->curve->_mulC($f)->redMul($g);
        }
        return $this->curve->point($nx, $ny, $nz);
    }

    public function add($p) {
        if ($this->isInfinity())
            return $p;
        if ($p->isInfinity())
            return $this;

        if ($this->curve->extended)
            return $this->_extAdd($p);
        else
            return $this->_projAdd($p);
    }

    public function mul($k) {
        if ($this->_hasDoubles($k))
            return $this->curve->_fixedNafMul($this, $k);
        else
            return $this->curve->_wnafMul($this, $k);
    }

    public function mulAdd($k1, $p, $k2) {
        return $this->curve->_wnafMulAdd(1, [ $this, $p ], [ $k1, $k2 ], 2, false);
    }

    public function jmulAdd($k1, $p, $k2) {
        return $this->curve->_wnafMulAdd(1, [ $this, $p ], [ $k1, $k2 ], 2, true);
    }

    public function normalize() {
        if ($this->zOne)
            return $this;

        $zi = $this->z->redInvm();
        $this->x = $this->x->redMul($zi);
        $this->y = $this->y->redMul($zi);
        if ($this->t)
            $this->t = $this->t->redMul($zi);
        $this->z = $this->curve->one;
        $this->zOne = true;
        return $this;
    }

    public function neg() {
        return $this->curve->point($this->x->redNeg(),
            $this->y,
            $this->z,
            ($this->t != null) ? $this->t->redNeg() : null);
    }

    public function getX() {
        $this->normalize();
        return $this->x->fromRed();
    }

    public function getY() {
        $this->normalize();
        return $this->y->fromRed();
    }

    public function eq($other) {
        return $this == $other ||
            $this->getX()->cmp($other->getX()) == 0 &&
            $this->getY()->cmp($other->getY()) == 0;
    }

    public function eqXToP($x) {
        $rx = $x->toRed($this->curve->red)->redMul($this->z);
        if ($this->x->cmp($rx) == 0)
            return true;

        $xc = $x->_clone();
        $t = $this->curve->redN->redMul($this->z);
        for (;;) {
            $xc->iadd($this->curve->n);
            if ($xc->cmp($this->curve->p) >= 0)
                return false;

            $rx->redIAdd($t);
            if ($this->x->cmp($rx) == 0)
                return true;
        }
        return false;
    }

    public function toP() { return $this->normalize(); }
    public function mixedAdd($p) { return $this->add($p); }
}
