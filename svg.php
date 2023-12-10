<?php

namespace HitchinHackspace\SVG;

use ArrayIterator;

/**
 * Something that can be embedded in an SVG document (despite the name, not necessarily a node)
 * @package HitchinHackspace\SVG
 */
abstract class Node {
    /** Write this object to standard out */
    abstract function output();     
}

/**
 * Generate a string encoding the specified parameter dictionary.
 * 
 * @param array<int, mixed> $attrs
 * @return string 
 */
function formatAttributes($attrs) {
    $result = '';

    foreach ($attrs as $key => $value) {
        $key = htmlspecialchars($key);
        $value = htmlspecialchars($value);
        $result .= " $key=\"$value\"";
    }

    return $result;
}

/**
 * A trait for Nodes that have descendants.
 */
trait Container {
    /** @var Node[] */
    protected $children = [];

    /**
     * @param Node $child 
     * @return void 
     */
    function addChild($child) {
        $this->children[] = $child;
    }
}

/**
 * Basic SVG path style properties.
 */
class Style {
    /** @var string $stroke Stroke colour */
    protected $stroke;
    /** @var string $fill Fill colour */
    protected $fill;
    /** @var float $strokeWidth Stroke width */
    protected $strokeWidth;
    /** @var float $strokeOpacity Stroke opacity */
    protected $strokeOpacity;
    /** @var float $fillOpacity Stroke width */
    protected $fillOpacity;

    /**
     * @param string $stroke 
     * @param string $fill 
     * @param float $strokeWidth 
     * @param float $strokeOpacity 
     * @param float $fillOpacity 
     */
    function __construct($stroke = 'black', $fill = 'black', $strokeWidth = 1, $strokeOpacity = 1, $fillOpacity = 1) {
        $this->stroke = $stroke;
        $this->fill = $fill;
        $this->strokeWidth = $strokeWidth;
        $this->strokeOpacity = $strokeOpacity;
        $this->fillOpacity = $fillOpacity;
    }

    /**
     * Convert this style to an attribute dictionary for embedding in a node.
     * 
     * @return array<string, mixed>
     */
    function getAttrs() {
        $attrs = [
            'stroke' => $this->stroke,
            'fill' => $this->fill,
            'stroke-width' => $this->strokeWidth,
            'stroke-opacity' => $this->strokeOpacity,
            'fill-opacity' => $this->fillOpacity
        ];

        return $attrs;
    }
}

/**
 * A basic rectangle.
 */
class Rect extends Node {
    /** @var float */
    protected $x;
    /** @var float */
    protected $y;
    /** @var float */
    protected $width;
    /** @var float */
    protected $height;
    /** @var Style */
    protected $style;

    /**
     * @param float $x 
     * @param float $y 
     * @param float $width 
     * @param float $height 
     * @param Style $style  
     */
    public function __construct($x, $y, $width, $height, $style) {
        $this->x = $x; $this->y = $y;
        $this->width = $width; $this->height = $height;
        $this->style = $style;
    }

    function output() {
        $attrs = $this->style->getAttrs();
        $attrs += [
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height
        ];

        ?>
            <rect <?= formatAttributes($attrs) ?> />
        <?php
    }
}

/**
 * A basic (single) line.
 */
class Line extends Node {
    /** @var float */
    protected $x1;
    /** @var float */
    protected $y1;
    /** @var float */
    protected $x2;
    /** @var float */
    protected $y2;
    /** @var Style */
    protected $style;

    /**
     * @param float $x1 
     * @param float $y1 
     * @param float $x2 
     * @param float $y2 
     * @param float $style 
     */
    public function __construct($x1, $y1, $x2, $y2, $style) {
        $this->x1 = $x1; $this->y1 = $y1;
        $this->x2 = $x2; $this->y2 = $y2;
        $this->style = $style;
    }

    function output() {
        $attrs = $this->style->getAttrs();
        $attrs += [
            'x1' => $this->x1,
            'y1' => $this->y1,
            'x2' => $this->x2,
            'y2' => $this->y2
        ];
        
        ?>
            <line <?= formatAttributes($attrs) ?> />
        <?php
    }
}

/**
 * A simple group (<g>) element.
 */
class Group extends Node {
    use Container;

    function output() {
        ?>
        <g>
            <?php 
                foreach ($this->children as $child)
                    $child->output();
            ?>
        </g>
        <?php
    }
}

/**
 * A text (<text>) element.
 */
class Text extends Node {
    protected $attributes = [];
    /** @var string */
    protected $content;

    /**
     * @param float $x 
     * @param float $y 
     * @param string $text 
     */
    public function __construct($x, $y, $text) {
        $this->attributes = [
            'x' => $x,
            'y' => $y
        ];
        
        $this->content = $text;
    }

    function output() {
        ?>            
            <text <?= formatAttributes($this->attributes) ?>><?= $this->content ?></text>
        <?php
    }
}

/**
 * An SVG document.
 */
class Document extends Node {
    use Container;

    /** @var float */
    protected $width;

    /** @var float */
    protected $height;

    /**
     * @param float $width 
     * @param float $height  
     */
    public function __construct($width, $height) {
        $this->width = $width;
        $this->height = $height;
    }

    /** @return float */
    public function width() { return $this->width; }

    /** @return float */
    public function height() { return $this->height; }

    function output() {
        $width = $this->width; $height = $this->height;
        $attrs = ['version' => '1.1', 'width' => $width, 'height' => $height, 'viewBox' => "0 0 $width $height", 'xmlns' =>'http://www.w3.org/2000/svg', 'class' => 'hh-energy-graph'];
        ?>
        <svg <?= formatAttributes($attrs) ?>>
            <?php 
                foreach ($this->children as $child)
                    $child->output();
            ?>
        </svg>
        <?php
    }
}

/**
 * Something that can form part of an SVG path definition.
 */
abstract class PathSegment {
    /** @var float[] Get the [x, y] co-ordinate of the start of this segment. */
    abstract function getStart();

    /** @var string[] Get the constituent path commands of this segment. */
    abstract function getCommands();

    /**
     * Generate an (absolute) move-to command.
     * @param float $x 
     * @param float $y 
     * @return string 
     */
    static function move($x, $y) {
        return "M $x $y";
    }

    /**
     * Generate an (absolute) line-to command.
     * @param float $x 
     * @param float $y 
     * @return string 
     */
    static function line($x, $y) {
        return "L $x $y";
    }

    /**
     * Generate an (absolute) quadratic-bezier-to command, optionally specifying the control point.
     * @param float $x 
     * @param float $y 
     * @param float $cx
     * @param float $cy
     * @return string 
     */
    static function quadratic($x, $y, $cx = null, $cy = null) {
        if ($cy) 
            return "Q $cx $cy, $x $y";
        return "T $x $y";
    }

    /**
     * Generate an (absolute) cubic-bezier-to command, optionally specifying the first control point.
     * @param float $x 
     * @param float $y 
     * @param float $cx2
     * @param float $cy2
     * @param float $cx1
     * @param float $cy1
     * @return string 
     */
    static function cubic($x, $y, $cx2, $cy2, $cx1 = null, $cy1 = null) {
        if ($cy1) 
            return "C $cx1 $cy1, $cx2 $cy2, $x $y";
        return "S $cx2 $cy2, $x $y";
    }
}

/**
 * An SVG <path> element.
 * @package HitchinHackspace\SVG
 */
class Path extends Node {
    /** @var Style */
    protected $style;
    /** @var PathSegment[] */
    protected $segments;

    /**
     * @param Style $style 
     * @param PathSegment[] $segments 
     */
    public function __construct($style, $segments) {
        $this->style = $style;
        $this->segments = $segments;
    }

    public function output() {
        $commands = [];

        $first = true;
        foreach ($this->segments as $segment) {
            [$x, $y] = $segment->getStart();

            if ($first) {
                $commands[] = PathSegment::move($x, $y);
                $first = false;
            }
            else {
                $commands[] = PathSegment::line($x, $y);
            }

            foreach ($segment->getCommands() as $command)
                $commands[] = $command;
        }

        ?>
            <path <?= formatAttributes($this->style->getAttrs() + ['d' => implode(' ', iterator_to_array($commands))]) ?> />
        <?php
    }
}

/**
 * A path running through points.
 */
abstract class JoinPoints extends PathSegment {
    /** @var float[][] */
    protected $points;
        
    /**
     * @param float[][] $points 
     */
    public function __construct($points) {
        $this->points = $points;
    }
}

/**
 * A path composed of linear segments between points.
 */
class LinearPath extends JoinPoints {
    public function getStart() {
        return $this->points[0];
    }

    public function getCommands() {
        $it = new ArrayIterator($this->points);
        $it->next();
        
        while ($it->valid()) {
            [$x, $y] = $it->current(); $it->next();
            yield self::line($x, $y);
        }
    }
}

class QuadraticPath extends JoinPoints {
    public function getStart() {
        return $this->points[0];
    }

    public function getCommands() {
        [$ox, $oy] = $this->getStart();

        $it = new ArrayIterator($this->points);
        $it->next();
        
        [$x, $y] = $it->current(); $it->next();
        yield self::quadratic($x, $y, ($ox + $x) / 2, ($oy + $y) / 2);

        while ($it->valid()) {
            [$x, $y] = $it->current(); $it->next();
            yield self::quadratic($x, $y);
        }
    }
}

class CubicPath extends JoinPoints {
    public function getStart() {
        return $this->points[0];
    }

    protected function getControlPoint($prev, $current, $next) {
        $k = 0.5;
        $m = 0.5;

        $gradient = $m * ($next[1] - $prev[1]) / ($next[0] - $prev[0]);
        $offset = $current[0] - $prev[0];
        $cp1 = [$current[0] - $k * $offset, $current[1] - $k * $offset * $gradient];
        // $cp2 = [$current[0] + $k * $offset, $current[1] + $k * $offset * $gradient];

        return $cp1;
    }

    public function getCommands() {
        $it = new ArrayIterator($this->points);

        $it->next();
        
        $current = $prev = $this->getStart();
        
        while ($it->valid()) {    
            $next = $it->current(); $it->next();
        
            [$cpx, $cpy] = $this->getControlPoint($prev, $current, $next);

            yield self::cubic($current[0], $current[1], $cpx, $cpy);
            [$prev, $current] = [$current, $next];
        }

        [$cpx, $cpy] = $this->getControlPoint($prev, $current, $current);

        yield self::cubic($current[0], $current[1], $cpx, $cpy);
    }
}