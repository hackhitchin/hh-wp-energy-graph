<?php

/**
 * Plugin Name: Energy Consumption Graph
 * Description: Display data from the Octopus Energy API.
 * Version: 0.2
 * Author: Mark Thompson
 * Update URI: https://github.com/hackhitchin/hh-wp-energy-graph
 */

namespace HitchinHackspace\EnergyGraph;

require_once __DIR__ . '/svg.php';

use DateTime;
use Exception;
use Generator;
use HitchinHackspace\SVG\Container;
use HitchinHackspace\SVG\CubicPath;
use HitchinHackspace\SVG\Document;
use HitchinHackspace\SVG\Group;
use HitchinHackspace\SVG\Line;
use HitchinHackspace\SVG\LinearPath;
use HitchinHackspace\SVG\Node;
use HitchinHackspace\SVG\Path;
use HitchinHackspace\SVG\PathSegment;
use HitchinHackspace\SVG\QuadraticPath;
use HitchinHackspace\SVG\Rect;
use HitchinHackspace\SVG\Style;
use HitchinHackspace\SVG\Text;

use function HitchinHackspace\SVG\formatAttributes;

/**
 * Adds a HTTP Basic authentication header to a request.
 * 
 * @param string $username 
 * @param string $password 
 * @return resource 
 */
function http_auth_context($username, $password) {
    $auth = base64_encode("$username:$password");

    return stream_context_create([
        'http' => [
            'header' => "Authorization: Basic $auth\r\nCache-Control: max-age=1800"
        ]
    ]);
}

/**
 * Request consumption data for the Hackspace's meter.
 * 
 * See https://developer.octopus.energy/docs/api/#list-consumption-for-a-meter for valid parameters
 * 
 * @param array $query 
 */
function octopus_api_request($query = []) {
    $mpan = OCTOPUS_MPAN;
    $serial = OCTOPUS_METER_SERIAL;
    $context = http_auth_context(OCTOPUS_API_KEY, '');
    $query = http_build_query($query);

    $data = file_get_contents("https://api.octopus.energy/v1/electricity-meter-points/$mpan/meters/$serial/consumption?$query", false, $context);

    if (!$data)
        return null;

    return json_decode($data, true);
}

/**
 * Request consumption data in the form of a timestamp => average power iterable.
 * 
 * @return Iterable<int, float> 
 */
function octopus_graph_data($query = []) {
    $response = octopus_api_request($query);

    $interval = getSampleIntervalHours($query['group_by'] ?? '');
    
    foreach ($response['results'] as $entry)
        yield strtotime($entry['interval_start']) => ($entry['consumption'] / $interval);
}

/**
 * Convert an Octopus 'group_by' parameter into an interval (in hours)
 * 
 * @param string $groupBy 
 * @return float
 */
function getSampleIntervalHours($groupBy) {
    if (!$groupBy)
        return 0.5;
    if ($groupBy == 'hour')
        return 1;
    if ($groupBy == 'day')
        return 24;
    if ($groupBy == 'week')
        return 7 * 24;
    throw new Exception('Unrecognized group_by in query');
}

/**
 * Aggregate a series of historical samples and do some basic stats on them.
 * 
 * @param float[] $consumption
 * @return array<string, float>
 */
function computeConsumptionStatistics($consumption) {
    $current = $consumption[0];

    // Extract mean and IQR.
    sort($consumption, SORT_NUMERIC);
        
    $count = count($consumption);
    $delta = floor($count / 4);

    $mean = array_sum($consumption) / $count;
    $q1 = $consumption[$delta];
    $q3 = $consumption[$count - 1 - $delta];

    return [
        'consumption' => $current,
        'average' => $mean,
        'q1' => $q1,
        'q3' => $q3
    ];
}

/**
 * Generate graph data by making an Octopus API request with the specified parameters, 
 * but also request extra data to generate longer-term statistics.
 * 
 * @param array $query
 * @param int $statsDuration How many multiples of the original query period should we request?
 * @return Iterable<array<string, int|float>>
 */
function octopus_graph_data_stats($query = [], $statsDuration = 10) {
    // How many samples have we actually been asked for?
    $count = $query['page_size'];

    // ... and how many should we actually request so we can do some maths on them?
    $query['page_size'] = $count * $statsDuration;

    /** @var array<int, float> $data */
    $data = iterator_to_array(octopus_graph_data($query));
    
    /** @var int[] $timestamps */
    $timestamps = array_keys($data);

    /** @var float[] $results */
    $results = array_values($data);

    // Collect matching samples and do a little light number crunching.
    for ($i = 0; $i < $count; ++$i) {
        /**
         * @var float[] consumption
         */
        $consumption = [];
        
        $offset = $i;

        while ($offset < count($results)) {
            $consumption[] = $results[$offset];

            // Step backwards in time by the analysis period.
            $offset += $count;
        }

        // It's possible we don't have enough data for even one complete period.
        if (!$consumption)
            break;

        $consumption = computeConsumptionStatistics($consumption);
        $consumption['timestamp'] = $timestamps[$i];
        yield $consumption;
    }
}

class AxisParameters {
    // y = mx + c
    /** @var float */
    private $m;
    /** @var float */
    private $c;

    /**
     * @param float $m 
     * @param float $c  
     */
    public function __construct($m, $c) {
        $this->m = $m;
        $this->c = $c;
    }

    /**
     * Return a mapping between (min, max) and (0, 1)
     * @param float $min 
     * @param float $max 
     * @return AxisParameters 
     */
    public static function fromRange($min, $max) {
        $m = 1 / ($max - $min);
        $c = -$min * $m;

        return new self($m, $c);
    }

    /**
     * Apply this transformation.
     * @param float $data 
     * @return float 
     */
    public function map($data) {
        return $data * $this->m + $this->c;
    }

    /**
     * Apply the reverse transformation.
     * @param float $data 
     * @return float 
     */
    public function unmap($data) {
        return ($data - $this->c) / $this->m;
    }

    /**
     * Concatenate this transformation with the reverse of the $displayAxis transformation.
     * 
     * @param mixed $displayAxis 
     * @return AxisParameters 
     */
    public function applyDisplay($displayAxis) {
        return new self($this->m / $displayAxis->m, ($this->c - $displayAxis->c) / $displayAxis->m);
    }
}

class DataPoint extends Node {
    /** @var float */
    protected $cx;
    /** @var float */
    protected $cy;
    /** @var float */
    protected $radius;
    /** @var Style */
    protected $style;
    /** @var string */
    protected $tooltip;
    
    /**
     * @param float $cx 
     * @param float $cy 
     * @param float $radius 
     * @param Style $style 
     * @param string $tooltip 
     */
    public function __construct($cx, $cy, $radius, $style, $tooltip) {
        $this->cx = $cx; $this->cy = $cy;
        $this->radius = $radius;
        $this->style = $style;
        $this->tooltip = $tooltip;
    }

    function output() {
        $attrs = $this->style->getAttrs();
        $attrs += [
            'cx' => $this->cx,
            'cy' => $this->cy,
            'r' => $this->radius
        ];

        ?>
            <circle <?= formatAttributes($attrs) ?>>
                <title><?= $this->tooltip ?></title>
            </circle>
        <?php
    }
}

/**
 * A rectangular area of the graph representing a period of interest.
 */
class IntervalBlock extends Rect {
    /** @var string */
    protected $tooltip;

    /**
     * @param float $x 
     * @param float $y 
     * @param float $width 
     * @param float $height 
     * @param Style $style 
     * @param string $tooltip 
     */
    public function __construct($x, $y, $width, $height, $style, $tooltip) {
        parent::__construct($x, $y, $width, $height, $style);

        $this->tooltip = $tooltip;
    }

    function output() {
        $attrs = $this->style->getAttrs();
        $attrs += [
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height
        ];

        $textAttrs = [
            'x' => $this->x + 0.5 * $this->width,
            'y' => $this->y + $this->height - 20,
            'text-anchor' => 'middle',
            'fill' => 'black',
            'font-size' => '10px'
        ];

        $outlineAttrs = [
            'x' => $this->x + 0.2 * $this->width,
            'width' => 0.6 * $this->width,
            'y' => $this->y + $this->height - 35,
            'height' => 23,
            'fill' => 'white',
            'stroke' => 'black',
            'stroke-width' => 1
        ]
        ?>
            <rect <?= formatAttributes($attrs) ?>>
                <title><?= $this->tooltip ?></title>
            </rect>
            <rect <?= formatAttributes($outlineAttrs) ?> />
            <text <?= formatAttributes($textAttrs) ?>><?= $this->tooltip ?></text>
        <?php
    }
}

/**
 * A labelled horizontal axis.
 */
class HorizontalAxis extends Group {
    /**
     * @param float $x 
     * @param float $y 
     * @param float $width 
     * @param Style $style 
     * @param float $power  
     */
    public function __construct($x, $y, $width, $style, $power) {
        $this->addChild(new Line($x, $y, $x + $width, $y, $style));
        $this->addChild(new Text($x, $y - 3, "$power kW"));  
    }
}

/** 
 * @param AxisParameters $timeAxis 
 * @param AxisParameters $powerAxis 
 * @param array<string, int|float> $data 
 * @param string $column 
 * @return array<float, float> 
 */

function getDataPoints($timeAxis, $powerAxis, $data, $column) {
    $remap = function($entry) use ($timeAxis, $powerAxis, $column) {
        $x = $timeAxis->map($entry['timestamp']);
        $y = $powerAxis->map($entry[$column]);

        return [$x, $y];
    };

    return array_map($remap, $data);
}

/**
 * A path forming a data line on the graph.
 */
class GraphLine extends Path {
    /**
     * @param AxisParameters $timeAxis 
     * @param AxisParameters $powerAxis 
     * @param array<string, int|float> $data 
     * @param string $column 
     * @param Style $style 
     */
    function __construct($timeAxis, $powerAxis, $data, $column, $style) {
        $points = getDataPoints($timeAxis, $powerAxis, $data, $column);
        parent::__construct($style, [new CubicPath($points)]);
    }
}

/**
 * A path that encloses a shaded area on the graph.
 */
class GraphRegion extends Path {
    /**
     * @param AxisParameters $timeAxis 
     * @param AxisParameters $powerAxis 
     * @param array<string, int|float> $data 
     * @param string $minColumn The array key containing the y-values of the lower bound of the region
     * @param string $maxColumn The array key containing the y-values of the upper bound of the region
     * @param Style $style 
     */
    function __construct($timeAxis, $powerAxis, $data, $minColumn, $maxColumn, $style) {
        $min = getDataPoints($timeAxis, $powerAxis, $data, $minColumn);
        $max = getDataPoints($timeAxis, $powerAxis, $data, $maxColumn);

        parent::__construct($style, [new CubicPath(array_reverse($min)), new CubicPath($max)]);
    }
}

/**
 * An overlay, shown on hover, containing details of consumption at a specific time.
 */
class GraphInfoOverlay extends Group {
    /**
     * @param AxisParameters $timeAxis 
     * @param AxisParameters $powerAxis 
     * @param array<string, int|float> $sample 
     * @param array<string, Style> $columns 
     */
    function __construct($timeAxis, $powerAxis, $sample, $columns) {
        $x = $timeAxis->map($sample['timestamp']);

        $nullStyle = new Style('none', 'black', 0, 0, 0);

        $this->addChild(new Rect($x - 5, 0, 10, 630, $nullStyle));
        $this->addChild(new Line($x, 0, $x, 630, new Style('black', 'none')));

        $ty = 20;
        $this->addChild(new Text($x + 3, $ty, date('Y-m-d H:i:s', $sample['timestamp']))); $ty += 15;

        foreach ($columns as $key => $style) {
            $power = $sample[$key];

            $y = $powerAxis->map($power);
            $this->addChild(new DataPoint($x, $y, 5, $style, 'Bob'));

            $label = ucwords($key) . ' ' . number_format($power, 2) . ' kW';

            $this->addChild(new Text($x + 3, $ty, $label)); $ty += 15;
        }
    }

    function output() {
        ?>
        <g class="overlay">
            <?php 
                foreach ($this->children as $child)
                    $child->output();
            ?>
        </g>
        <?php
    }
}

/**
 * Generate breakpoints in an interval.
 * 
 * @param int $periodStart The timestamp beginning the range of interest.
 * @param int $periodEnd The timestamp ending the range of interest.
 * @param string $relativePrev The parameter to DateTime::modify() that goes back to the start of the previous period.
 * @param string $relativeNext The parameter to DateTime::modify() that advances to the start of the next period.
 * @param $format A function that generates a label from a start and end date.
 * 
 * @return Iterable<int, string>
 */
function getRelativeDivisions($periodStart, $periodEnd, $relativePrev, $relativeNext, $format) {
    $timezone = timezone_open('Europe/London');

    $date = new DateTime('now', $timezone);
    $date->setTimestamp($periodStart);
    $date->modify($relativePrev);
    
    $endDate = new DateTime('now', $timezone);
    $endDate->setTimestamp($periodEnd);

    while (true) {
        $next = (clone $date)->modify($relativeNext);

        yield $date->getTimestamp() => $format($date, $next);
        if ($date > $endDate)
            return;

        $date = $next;
    }
}

/**
 * Break a period into four-hour chunks.
 * @param int $start 
 * @param int $end 
 * @return iterable<int, string> 
 */
function getFourHourDivisions($start, $end) {
    return getRelativeDivisions($start, $end, 'midnight', '+4 hours', function($start, $end) {
        $start = $start->format('ga'); $end = $end->format('ga');
        return "$start to $end";
    });
}

/**
 * Break a period into day-long chunks.
 * @param int $start 
 * @param int $end 
 * @return iterable<int, string> 
 */
function getDayDivisions($start, $end) {
    return getRelativeDivisions($start, $end, 'midnight', '+1 day', function($start, $end) {
        return $start->format('l');
    });
}

/**
 * Break a period into week-long chunks.
 * @param int $start 
 * @param int $end 
 * @return iterable<int, string> 
 */
function getWeekDivisions($start, $end) {
    return getRelativeDivisions($start, $end, 'midnight last sunday', '+1 week', function($start, $end) {
        return 'Week ' . $start->format('W');
    });
}

/**
 * Break a period into month-long chunks.
 * @param int $start 
 * @param int $end 
 * @return iterable<int, string> 
 */
function getMonthDivisions($start, $end) {
    return getRelativeDivisions($start, $end, 'first day of last month', '+1 month', function($start, $end) {
        return $start->format('F');
    });
}

/**
 * Work out a sensible spacing for vertical axis gridlines.
 * @param float $max The maximum value the axis should cover (minimum of zero is implied)
 * @param int $desiredIntervals How many gridlines we'd like.
 * @return float
 */
function selectDivisionInterval($max, $desiredIntervals) {
    // Get the next-highest order of magnitude.
    $interval = pow(10, ceil(log10($max)));

    $try = [1, 0.5, 0.2];

    while (true) {
        foreach ($try as $t) {
            $t = $interval * $t;
            if ($max / $t > $desiredIntervals)
                return $t;
        }
        $interval *= 0.1;
    }
}

add_shortcode('hh_energy_graph', function($attrs) {
    if (!$attrs)
        $attrs = [];

    ob_start();

    $query = [];

    $permit_query_params = ['page_size', 'group_by'];

    foreach ($permit_query_params as $param)
        if (array_key_exists($param, $attrs))
            $query[$param] = $attrs[$param];

    try {
        $graph = new Document(840, 630);
        
        $data = iterator_to_array(octopus_graph_data_stats($query));
        
        $periodStart = min(array_column($data, 'timestamp'));
        $periodEnd = max(array_column($data, 'timestamp'));

        $timeAxis = AxisParameters::fromRange($periodStart, $periodEnd);
        $timeAxis = $timeAxis->applyDisplay(AxisParameters::fromRange(0, $graph->width()));

        // Highlight useful sections in alternating colours.
        $groupBy = $query['group_by'] ?? '';
        
        if ($groupBy == 'hour') // Used in the '7-day' view
            $divisions = getDayDivisions($periodStart, $periodEnd);
        else if ($groupBy == 'day') // Used in the '60-day' view
            $divisions = getWeekDivisions($periodStart, $periodEnd);
        else if ($groupBy == 'week') // Used in the one-year view
            $divisions = getMonthDivisions($periodStart, $periodEnd);
        else // Used in the one-day view.
            $divisions = getFourHourDivisions($periodStart, $periodEnd);

        $blockStyles = [
            new Style('yellow', 'yellow', 1, 0.5, 0.2),
            new Style('yellow', 'yellow', 1, 0, 0)
        ];

        $blockStart = null;
        $blockName = null;
        $blockIndex = 0;

        foreach ($divisions as $division => $label) {
            if ($blockStart) {
                $x0 = $timeAxis->map($blockStart);
                $x1 = $timeAxis->map($division);    

                $i = $blockIndex++ % count($blockStyles);

                $graph->addChild(new IntervalBlock($x0, 0, $x1 - $x0, $graph->height(), $blockStyles[$i], $blockName));
            }

            $blockStart = $division;
            $blockName = $label;
        }

        $reduce = function($fn) use ($data) {
            return array_reduce(array_map(function($column) use ($fn, $data) {
                return array_reduce(array_column($data, $column), $fn);
            }, ['consumption', 'average', 'q1', 'q3']), $fn);
        };

        // $min = function($a, $b) { return min($a, $b); };
        $max = function($a, $b) { return max($a, $b); };
        $max = $reduce($max);

        $powerAxis = AxisParameters::fromRange(0, $max);
        $powerAxis = $powerAxis->applyDisplay(AxisParameters::fromRange($graph->height() - 70, 30));

        $interval = selectDivisionInterval($max, 4);
        error_log(print_r($powerAxis, true));

        $p = 0;
        while (true) {
            $y = $powerAxis->map($p);
            if ($y < 0)
                break;
            $graph->addChild(new HorizontalAxis(0, $y, $graph->width(), new Style(), $p));
            $p += $interval;
        }
        
        $graph->addChild(new GraphRegion($timeAxis, $powerAxis, $data, 'q1', 'q3', new Style('none', 'blue', 1, 1, 0.1)));
        $graph->addChild(new GraphLine($timeAxis, $powerAxis, $data, 'consumption', new Style('black', 'none')));
        $graph->addChild(new GraphLine($timeAxis, $powerAxis, $data, 'average', new Style('blue', 'none')));
        $graph->addChild(new GraphLine($timeAxis, $powerAxis, $data, 'q1', new Style('green', 'none')));
        $graph->addChild(new GraphLine($timeAxis, $powerAxis, $data, 'q3', new Style('red', 'none')));

        foreach ($data as $sample) {
            $graph->addChild(new GraphInfoOverlay($timeAxis, $powerAxis, $sample, [
                'consumption' => new Style('black', 'black'),
                'average' => new Style('blue', 'blue'),
                'q1' => new Style('green', 'green'),
                'q3' => new Style('red', 'red')
            ]));
        }

        $graph->output();

        return ob_get_contents();
    }
    finally {
        ob_end_clean();
    }
});