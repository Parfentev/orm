<?php

namespace ORM\Connection;

use wpdb;

class WordPressConnection implements ConnectionInterface
{
    private wpdb $wpdb;

    public function __construct(wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }
}