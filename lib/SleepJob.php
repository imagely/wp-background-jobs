<?php

namespace ReactrIO\Background;

class SleepJob extends Job
{
    function run()
    {
        array_map('sleep', array_fill(0, $this->get_dataset(), 1));
        return $this;
    }
}