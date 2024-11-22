<?php
/**
 * Class TVLM_Loader
 * Responsible for coordinating all plugin components and hooks
 */
class TVLM_Loader {
    protected $actions;
    protected $filters;
    protected $shortcodes;

    public function __construct() {
        $this->actions = [];
        $this->filters = [];
        $this->shortcodes = [];
    }

    public function add_action($hook, $component, $callback, $priority = 10, $accepted_args = 1) {
        $this->actions = $this->add($this->actions, $hook, $component, $callback, $priority, $accepted_args);
    }

    public function add_filter($hook, $component, $callback, $priority = 10, $accepted_args = 1) {
        $this->filters = $this->add($this->filters, $hook, $component, $callback, $priority, $accepted_args);
    }

    public function add_shortcode($tag, $component, $callback) {
        $this->shortcodes = $this->add($this->shortcodes, $tag, $component, $callback, 0, 0);
    }

    private function add($hooks, $hook, $component, $callback, $priority, $accepted_args) {
        $hooks[] = [
            'hook'          => $hook,
            'component'     => $component,
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $accepted_args
        ];
        return $hooks;
    }

    public function run() {
        // Register all actions
        foreach ($this->actions as $hook) {
            add_action(
                $hook['hook'],
                [$hook['component'], $hook['callback']],
                $hook['priority'],
                $hook['accepted_args']
            );
        }

        // Register all filters
        foreach ($this->filters as $hook) {
            add_filter(
                $hook['hook'],
                [$hook['component'], $hook['callback']],
                $hook['priority'],
                $hook['accepted_args']
            );
        }

        // Register all shortcodes
        foreach ($this->shortcodes as $shortcode) {
            add_shortcode(
                $shortcode['hook'],
                [$shortcode['component'], $shortcode['callback']]
            );
        }
    }
}