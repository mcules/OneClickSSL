<?php
/**
* GlobalSign OneclickSSL extention for Nginx
*
* Replacing the slow and error prone process of CSR creation, key management, 
* approver emails and Certificate installation with a single click!
*
* PHP version 5
*
* LICENSE: BSD License
*
* Copyright © 2012 GMO GlobalsSign KK.
* All Rights Reserved.
*
* Redistribution and use in source and binary forms, with or without
* modification, are permitted provided that the following conditions 
* are met:
* 1. Redistributions of source code must retain the above copyright
*    notice, this list of conditions and the following disclaimer.
* 2. Redistributions in binary form must reproduce the above copyright
*    notice, this list of conditions and the following disclaimer in the
*    documentation and/or other materials provided with the distribution.
* 3. The name of the author may not be used to endorse or promote products
*    derived from this software without specific prior written permission.
* 
* THIS SOFTWARE IS PROVIDED BY GMO GLOBALSIGN KK "AS IS" AND ANY EXPRESS OR
* IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES
* OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
* IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, 
* INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT 
* NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
* DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
* THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT 
* (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF
* THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*
* @copyright  Copyright © 2012 GMO GlobalsSign KK. All Rights Reserved. (http://www.globalsign.com)
* @license    BSD License (3 Clause)
* @version    $Id$
* @link       http://www.globalsign.com/ssl/oneclickssl/
*/

define("PLATFORMID", "081143925200001");
define("KEYALGORITHM", "RSA");

// Directory, for config file per site
define("CONFIGDIR", "/etc/nginx/sites-available/");

// Single config file, containing all sites
// Should be check all *.conf files in this dir?
// include /etc/nginx/conf.d/*.conf;
// include /etc/nginx/sites-enabled/*;
define("CONFIGNGINX", "/etc/nginx/conf.d/default.conf");

// Read/write permissions for root only (chmod 600)
define("CERTDIR", "/etc/ssl/oneclickssl/");

require("../../lib/OneClickSSL.php");

class NginxOneClick implements OneClickSSLPlugin
{
    protected $_output;

    protected $_domain;

    /**
     * Set the domain for the certificate
     *
     * @param string $domain  The domain for the certificate
     *
     * @return null
     */
    public function setDomain($domain)
    {
        $this->_domain = $domain;
    }

    /**
     * Install the certificate
     */
    public function install($privateKey, $certificate, $cacert = null)
    {
        $this->debug(1, "Preparing Nginx certificate installation for ". $this->_domain);
                        
        // Just some debugging information
        $this->debug(1, "Exporting certificates to the file system");
        $this->debug(2, "Certificate:\n". $certificate);
        $this->debug(2, "Intermediates:\n". $cacert);
            
        // Export the certificates to the filesystem
        openssl_pkey_export_to_file($privateKey, CERTDIR . $this->_domain . ".key");
        openssl_x509_export($certificate,$certString);
        if (strlen($cacert) > 10) {
            openssl_x509_export($cacert,$cacertString);		
        }
        
        // Create a PEM file with certificate combined with the intermediate certificate(s)
        file_put_contents(CERTDIR . $this->_domain . ".pem", $certString . $cacertString);
        
        // Set rw file permissions for root only
        chmod(CERTDIR . $this->_domain . ".key", 600);
        chmod(CERTDIR . $this->_domain . ".pem", 600);
        
        /**
         * Update Nginx config
         */
        $type = '';
        $srvInfo = '';
        $newConfig = '';
        $servers = array();
        $sslDone = false;
        $block = 0;
        
        // Check for a Nginx config file 
        if (file_exists(CONFIGDIR . $this->_domain)) {
            $file = CONFIGDIR . $this->_domain;
        } elseif (file_exists(CONFIGDIR . $this->_domain .'.conf')) {
            $file = CONFIGDIR . $this->_domain .'.conf';
        } elseif (file_exists(CONFIGNGINX)) {
            $file = CONFIGNGINX;
        }
        
        $this->debug(2, "Using configuration file: ". $file);
        
        // Open Nginx config, and walk through it line by line
        $handle = @fopen($file, "r");
        if ($handle) {
            while (($buffer = fgets($handle, 4096)) !== false) {

                // Start of config block
                if (preg_match("/([a-z0-9]*)[\s\t]?([\/a-z0-9]*)[\s\t]?\{/", trim($buffer), $result)) {
                    // We are mostly interested in the server block, if no ssl is configured, we need to copy the blo
                    if ($result[1] === 'server') {
                        $type = 'server';
                        $srvInfo = '';
                        $block = 0;
                    }
                    $block++;
                }

                // End of config block
                if (strstr($buffer, '}')) {
                    $block--;
                }

                // We care about the server block
                if ($type === 'server') {
                    if (preg_match("/([a-z0-9\_][^\t\s]*)[\s\t]*(.*);/i", $buffer, $srvMatch)) {

                        // We need some information, to be used later
                        // - Could contain more then one (www.globalsign.com globalsign.com)
                        if ($srvMatch[1] === 'server_name') {
                            if (strstr(trim($srvMatch[2]), ' ')) {
                                if (array_key_exists('server_alias', $srvConfig) && is_array($srvConfig['server_alias'])) {
                                    $srvConfig['server_alias'] = array_merge($srvConfig['server_alias'], explode(' ', trim($srvMatch[2])));
                                } else {
                                    $srvConfig['server_alias'] = explode(' ', trim($srvMatch[2]));
                                }
                            } else {
                                if (array_key_exists('server_alias', $srvConfig) && is_array($srvConfig['server_alias'])) {
                                    $srvConfig['server_alias'][] = trim($srvMatch[2]);
                                    
                                } else {
                                    $srvConfig['server_alias'][] = trim($srvMatch[2]);
                                }
                            }
                        
                        $srvConfig['server_name'] = $srvConfig['server_alias'][0];
                        
                        // Find the IPv6 address of this website
                        // - If no ip is listed, this is defnitly a name-based virtual host
                        } elseif ($srvMatch[1] === 'listen' && strstr($srvMatch[2], '::')) {
                            $srvConfig['ipv6'] = trim($srvMatch[2]);

                        // Find the IPv4 address of this website
                        // - If no ip is listed, this is a name-based virtual host
                        } elseif ($srvMatch[1] === 'listen') {
                            $srvConfig['ipv4'] = trim($srvMatch[2]);

                        }
                                
                        // Update this setting if there is a SSL based virtual host for this server
                        if(strpos($srvMatch[1], 'ssl') === 0 
                            && in_array($this->_domain, $srvConfig['server_alias'])) {
                            
                            switch($srvMatch[1]) {
                                // Turn SSL on
                                case "ssl":
                                    $srvInfo .= "\tssl on;". PHP_EOL;
                                    break;
                                
                                // Certificate combined with the intermediate certificate(s)
                                case "ssl_certificate":
                                    $srvInfo .= "\tssl_certificate ". CERTDIR . $this->_domain .".pem;". PHP_EOL;
                                    break;
                                
                                // Private key
                                case "ssl_certificate_key":
                                    $srvInfo .= "\tssl_certificate_key ". CERTDIR . $this->_domain .".key;". PHP_EOL;
                                    break;
                                
                                default:
                                    $srvInfo .= $buffer;
                            }
                            
                            // We found and updated a vhost with ssl for this domain
                            $sslDone = true;
                            
                        } else {
                            // Do not change this line of the config file
                            $srvInfo .= $buffer;
                        }

                    } else {
                        // Do not change this line of the config file
                        $srvInfo .= $buffer;
                    }

                    // This was the last bit of the server block
                    if ($block === 0) {
                        // Add the 'modified' server config
                        $newConfig .= $srvInfo;
                            
                        $servers[$srvConfig['server_name']][] = $srvInfo;
                        $srvInfo = '';
                        $type = '';
                    }
                } else {
                    // We don't want to change this part but don't want to loose it either
                    $newConfig .= $buffer;
                }
            }
            if (!feof($handle)) {
                echo "Error: unexpected fgets() fail\n";
            }
            fclose($handle);
        } else {
            $this->debug(1, "Error opening Nginx configuration file: ". $file);
            return false;
        }
        
        // Existing secure virtualhost config updated
        if ($sslDone) {
            $this->debug(1, "Existing Nginx site configuration updated");
        
        // No SSL config for this domain, add SSL config to the end of the file		
        } elseif (!$sslDone && array_key_exists($this->_domain, $servers)) {
            $sslConfig = substr(trim($servers[$this->_domain][0]), 0, -1);
            
            // The server should not listen on port 80 but port 443
            $sslConfig = preg_replace("/(listen[^\t\s]*[\s\t]*.*)80;/i", "\${1}443;", $sslConfig);
            
            // Add the SSL configuration
            $sslConfig .= "\tssl on;". PHP_EOL;
            $sslConfig .= "\tssl_certificate ". CERTDIR . $this->_domain .".pem;". PHP_EOL;
            $sslConfig .= "\tssl_certificate_key ". CERTDIR . $this->_domain .".key;". PHP_EOL;
            $sslConfig .= "\tssl_session_timeout  5m;". PHP_EOL;
            $sslConfig .= "\tssl_protocols  SSLv3 TLSv1;". PHP_EOL;
            $sslConfig .= "\tssl_ciphers  ALL:!ADH:!EXPORT56:RC4+RSA:+HIGH:+MEDIUM:+LOW:+SSLv3:+EXP;". PHP_EOL;
            $sslConfig .= "\tssl_prefer_server_ciphers   on;". PHP_EOL;
            
            // We stripped the last }, so we have to add it back
            $sslConfig .= "}". PHP_EOL;
        
            // Add the new ssl site to the config
            $newConfig .= $sslConfig;
            
            $this->debug(1, "Added a new secure site to the Nginx configuration");
        
        // Error, config not found
        } else {
            $this->debug(1, "Cannot find any configuration for this site");
            return false;
        }
        
        // Write the new config file to disk
        file_put_contents($file, $newConfig);
        
        // Reload Nginx
        exec("/etc/init.d/nginx reload", $configReload, $configReloadResult);
        
        if ($configReloadResult <> 0) {
            $this->debug(1, "Error while reloading Nginx configuration");
            $this->debug(2, implode($configReload, PHP_EOL));
            return false;
        }
            
        // Return certificate for installation check
        return $certString;
    }
	
    /**
     * Back the current certificates
     */     
    public function backup()
    {
        if (@copy(CERTDIR . $this->_domain .'.*', CERTDIR .'backup/')) {
            return true;
        } else {
            return false;
        }
    }
	
    /**
     * Restore the backup certificates
     */     
    public function restoreBackup()
    {
        if (@copy(CERTDIR .'backup/'. $this->_domain .'.*', CERTDIR)) {
            return true;
        } else {
            return false;
        }
    }
    
    /**
     * Set the output handler object
     *
     * @param Output_Output $output  Output handler object
     *
     * @return DAOneClick
     */
    public function setOutput(Output_Output $output)
    {
        $this->_output = $output;
        return $this;
    }
    
    /**
     * Write a message to the debugger
     *
     * @param int    $level    Level of message to send to debug
     * @param string $message  Message to send
     *
     * @return null
     */
    protected function debug($level, $message)
    {
        $this->_output->debug()->write($level, $message);
    }
}

// Create certificate directory if not exists
if (!is_dir(CERTDIR .'backup')) {
	mkdir(CERTDIR .'backup', 600, true);
}

/**
 * Initiate OneClickSSL Procedure
 *  $domain, $email, $voucher, $port = self::DEFAULT_SSL_PORT, $lang = self::DEFAULT_LANG 
 */
$certData = new CertificateData('remote.paul.vanbrouwershaven.com',
                                'paul.vanbrouwershaven@globalsign.com',
                                '5daytrialDV');
  
$oneclick = OneClickSSL::init($certData, new NginxOneClick());

$oneclick->output()->debug()->setLevel(1);

// Run on production (0), testing (1) or staging server (2)
//$oneclick->setEnvironment(1);

// Write procgress into status file (default: 0)
//$oneclick->output()->status()->setStatusPath(realpath('/tmp/'))->setWriteStatus(true);

$oneclick->order();