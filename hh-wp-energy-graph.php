<?php

/**
 * Plugin Name: Energy Consumption Graph
 * Description: Display data from the Octopus Energy API.
 * Version: 0.1
 * Author: Mark Thompson
 * Update URI: https://github.com/hackhitchin/hh-wp-energy-graph
 */

namespace HitchinHackspace\EnergyGraph;

function http_auth_context($username, $password) {
    $auth = base64_encode("$username:$password");

    return stream_context_create([
        'http' => [
            'header' => "Authorization: Basic $auth"
        ]
    ]);
}

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

function octopus_graph_data($query = []) {
    $response = octopus_api_request($query);

    foreach ($response['results'] as $entry)
        yield $entry['interval_start'] => $entry['consumption'];
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
        require_once __DIR__ . '/vendor/svggraph/autoloader.php';

        $graph = new \Goat1000\SVGGraph\SVGGraph(840, 630, ['datetime_keys' => true]);
        $graph->values(iterator_to_array(octopus_graph_data($query)));

        echo $graph->fetch('LineGraph', false);
        echo $graph->fetchJavascript();

        return ob_get_contents();
    }
    finally {
        ob_end_clean();
    }
});