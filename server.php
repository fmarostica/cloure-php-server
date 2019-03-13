<?php
    Namespace Cloure;
    require_once __DIR__."/CloureClient.php";

    /**
     * Indicates no limiti time execution
     */
    set_time_limit(0);

    /**
     * Allow implicit flush (for display in screen while running)
     */
    ob_implicit_flush();
    date_default_timezone_set("America/Argentina/Cordoba");
    header('Content-Type: text/html; charset=utf-8');
    
    /**
     * Starts a news instance for the server
     */
    $server = new CloureServer();
    $server->start();

    class CloureServer
    {
        private $Protocol       = "https";          //protocol that will be use
        private $APIFolder      = "api";            //API folder path
        private $Host           = "cloure.com";     //Host
        private $APIVersion     = 1;                //API version with will work
        private $address        = "0.0.0.0";        //Adress binded 0.0.0.0 -> all
        private $port           = "2083";           // Port
        private $max_clients    = 0;                //0 unlimited
        private $logging        = true;             //If logging enabled
        private $topic          = "";               //Topic of the Cloure Request
        private $cloureClients  = [];               //Array containing cloure clients

        public function start()
        {
            $this->logToFile("Server started");

            if (($sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
                $this->logToFile("socket_create() falló: razón: " . socket_strerror(socket_last_error()));
            }

            socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);

            if (socket_bind($sock, $this->address, $this->port) === false) {
                $this->logToFile("socket_bind() falló: razón: " . socket_strerror(socket_last_error($sock)));
            }
            
            if (socket_listen($sock, $this->max_clients) === false) {
                $this->logToFile("socket_listen() falló: razón: " . socket_strerror(socket_last_error($sock)));
            }

            $this->logToFile("CloureServer listening at port ".$this->port);

            $clients = array($sock);
            
            $this->logToFile("running clients ".(count($clients) - 1));

            do {
                $response = "";
                $error = "";
                
                $read = $clients;
                $write = $clients;
                $except = $clients;

                if (socket_select($read, $write, $except, 0) < 1) continue;
                
                // check if there is a client trying to connect
                if (in_array($sock, $read))
                {
                    $clients[] = $newsock = socket_accept($sock);

                    $welcome_message = "Welcome to Cloure server";
                    $welcome_message_encoded = json_encode(array("Topic"=>"welcome_message", "Response"=>$welcome_message, "Error"=>$error));
                    socket_write($newsock, $welcome_message_encoded);
                    socket_getpeername($newsock, $ip, $port);

                    $this->logToFile("New client connected from ".$ip);
                    $this->logToFile("Sended response: ".$welcome_message);

                    $key = array_search($sock, $read);
                    unset($read[$key]);
                }

                // loop through all the clients that have data to read from
                foreach ($read as $read_sock)
                {
                    // read until newline or 1024 bytes
                    // socket_read while show errors when the client is disconnected, so silence the error messages
                    $data_encoded = @socket_read($read_sock, 1024, PHP_BINARY_READ);

                    // check if the client is disconnected
                    if ($data_encoded === false)
                    {
                        // remove client for $clients array
                        $key = array_search($read_sock, $clients);
                        unset($clients[$key]);
                        $this->logToFile("Socket disconnected");

                        //remove client for $cloureClients array
                        $cloureClientIndex = array_search($read_sock, array_column($this->cloureClients, "socket"));
                        
                        if($cloureClientIndex!==false){
                            $this->logToFile("Cloure client disconnected: ".$this->cloureClients[$cloureClientIndex]->name." ".$this->cloureClients[$cloureClientIndex]->last_name);
                            unset($this->cloureClients[$cloureClientIndex]);
                        }

                        continue;
                    }
                    if($data_encoded=="") continue;

                    $this->logToFile("Data received from client: ".$data_encoded);
                    $data_encoded = trim($data_encoded);

                    if (!empty($data_encoded))
                    {
                        try {
                            $topic = "";
                            $data_decoded = @json_decode($data_encoded);
                            if($data_decoded==null) throw new \Exception("Malformed JSON input", 1);
                            $response = $this->execute($data_decoded);

                            //Check if is login response to add new cloureClient Class for broadcasting
                            try {
                                $response_decoded = json_decode($response);
                                if(isset($response_decoded->Topic)){
                                    if($response_decoded->Topic=="login_response"){
                                        if($response_decoded->Error==""){
                                            $appToken = $response_decoded->Response->app_token;
                                            $user_name = $response_decoded->Response->name;
                                            $user_last_name = $response_decoded->Response->last_name;
                                            $this->cloureClients[] = new CloureClient($read_sock, $appToken);
                                            $this->broadcast($read_sock, $user_name." ".$user_last_name." ha iniciado sesion!", $appToken);
                                        }
                                    }
                                }
                            } catch (\Exception $ex2) {
                                $this->logToFile("Error: ".$ex2->getMessage());
                            }

                        } catch (\Exception $ex) {
                            $this->logToFile("Error: ".$ex->getMessage());
                        }
                        
                        $msg_send = socket_write($read_sock, $response, strlen($response));

                        $this->logToFile("Sended response: ".$response);
                        
                        if($msg_send===FALSE){
                            break;
                        }

                        $length = strlen($response);
                        if ($msg_send < $length) {
                            // If not sent the entire message.
                            // Get the part of the message that has not yet been sented as message
                            $st = substr($st, $msg_send);
                            $length -= $sent;
                        } else {
                            break;
                        }

                        //socket_close($read_sock);
                    }

                } 
            } while (true);
            socket_close($sock);
        }

        /**
         * Execute a CloureClient Request Topic
         *
         * @param array $params
         * @return void
         */
        protected function execute($params=array()){
            $url = $this->Protocol."://".$this->Host."/".$this->APIFolder."/v".$this->APIVersion."/";

            $ch = curl_init( $url );
            curl_setopt( $ch, CURLOPT_POST, 1);
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $params);
            curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt( $ch, CURLOPT_HEADER, 0);
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0); //disable after 
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0); //disable after
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if($httpCode == 404) {
                return json_encode(["Response"=>"error", "Error"=>"Getting 404 err code while executed command"]);
            } elseif ($httpCode == 500) {
                return json_encode(["Response"=>"error", "Error"=>"Getting 500 err code while executed command"]);
            }
            else {
                if($response===false){
                    return json_encode(curl_error($ch));
                }
                else {
                    return $response;
                }
            }
            
            curl_close($ch);
        }

        /**
         * Send a message to all connected clients, except the first one, which is the listening socket
         */
        protected function broadcast($current_socket, $message, $app_id, $type="all"){
            $this->logToFile("Requesting broadcast for app id: ".$app_id);
            $this->logToFile("Broadcast message: ".$message);
            
            if(count($this->cloureClients)>0){
                foreach ($this->cloureClients as $cloureClient) {
                    $client_app_id = $cloureClient->getAppId();
                    if($app_id==$client_app_id)
                    {
                        if($cloureClient->socket == $current_socket) continue;
                        $message_arr = array("Topic"=>"broadcast_message", "Response"=>array("message"=>$message));
                        $message_encoded = json_encode($message_arr);
                        @socket_write($cloureClient->socket, $message_encoded);
                    }
                }
            }
        }

        /**
         * Log to logs file
         *
         * @param string $message
         * @param string $type
         * @return void
         */
        protected function logToFile($message, $type="I")
        {
            //check if logging is enabled
            if($this->logging){
                $logsFolder = __DIR__."/logs";
                if(!file_exists($logsFolder)) mkdir($logsFolder);
                $logfile = $logsFolder."/logs-".date("Y-m-d-H").".txt";

                $content = "[".date("d/M/Y H.i:s")."] ";
                $content.= $message;
                file_put_contents($logfile, $content . "\n", FILE_APPEND | LOCK_EX);
            }
        }
    }
?>