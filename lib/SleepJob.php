<?php

namespace ReactrIO\Background;

class SleepJob extends Job
{
    function __construct(array $props=[])
    {
        parent::__construct($props);
        $this->_time_estimate = $this->_dataset;
    }

    function run()
    {
        array_map('sleep', array_fill(0, $this->get_dataset(), 1));
        return $this;
    }
}