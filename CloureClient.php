<?php

    namespace Cloure;

    class CloureClient
    {
        private $soket = null;
        private $app_id = "";

        public $name = "";
        public $last_name = "";

        function __construct($socket, $app_id)
        {
            $this->socket = $socket;
            $this->app_id = $app_id;
        }

        public function getAppId()
        {
            return $this->app_id;
        }
    }


?>