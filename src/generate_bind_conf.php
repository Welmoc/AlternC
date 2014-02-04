#!/usr/bin/php -q
<?php

/**
  *
  * Generate Bind configuration for AlternC
  *
  * To force generation, /launch/generate_bind_conf.php force
  *
  *
 **/

require_once("/usr/share/alternc/panel/class/config_nochk.php");
ini_set("display_errors", 1);


class m_bind_regenerate {
  var $ZONE_TEMPLATE ="/etc/alternc/templates/bind/templates/zone.template";
  var $NAMED_TEMPLATE ="/etc/alternc/templates/bind/templates/named.template";
  var $NAMED_CONF ="/var/lib/alternc/bind/automatic.conf";
  var $RNDC ="/usr/sbin/rndc";

  var $cache_conf_db = array();
  var $cache_zone_file = array();
  var $cache_domain_summary = array();
  var $zone_file_directory = '/var/lib/alternc/bind/zones/';

  function m_bind_regenerate() {
    // Constructeur
  }

  // Return the part of the conf we got from the database
  function conf_from_db($domain=false) {
    global $db;
    if (empty($this->cache_conf_db)) {
      $db->query("
        select 
          sd.domaine, 
          replace(replace(dt.entry,'%TARGET%',sd.valeur), '%SUB%', if(length(sd.sub)>0,sd.sub,'@')) as entry 
        from 
          sub_domaines sd,
          domaines_type dt 
        where 
          sd.type=dt.name 
          and sd.enable in ('ENABLE', 'ENABLED') 
        order by entry ;");
      while ($db->next_record()) {
        $t[$db->f('domaine')][] = $db->f('entry');
      }
      $this->cache_conf_db = $t;
    }
    if ($domain) {
      if (isset($this->cache_conf_db[$domain])) {
        return $this->cache_conf_db[$domain];
      } else {
        return array();
      }
    } // if domain
    return $this->cache_conf_db;
  }

  function get_zone_file_uri($domain) {
    return $this->zone_file_directory.$domain;
  }

  function get_zone_file($domain) {
    if (!isset($this->cache_zone_file[$domain]) ) {
      if (file_exists($this->get_zone_file_uri($domain))) {
        $this->cache_zone_file[$domain] = @file_get_contents($this->get_zone_file_uri($domain));
      } else {
        $this->cache_zone_file[$domain] = false;
      }
    }
    return $this->cache_zone_file[$domain] ;
  }

  function get_serial($domain) {
    // Return the next serial the domain must have.
    // Choose between a generated and an incremented.
    
    // Calculated :
    $calc = date('Ymd').'00'."\n";

    // Old one :
    $old=$calc; // default value
    $file = $this->get_zone_file($domain);
    preg_match_all("/\s*(\d{10})\s+\;\sserial\s?/", $file, $output_array);
    if (isset($output_array[1][0]) && !empty($output_array[1][0])) {
      $old = $output_array[1][0];
    }

    return max(array($calc,$old)) + 1 ;
  }

  // Return lines that are after ;;;END ALTERNC AUTOGENERATE CONFIGURATION
  function get_persistent($domain) {
    preg_match_all('/\;\sEND\sALTERNC\sAUTOGENERATE\sCONFIGURATION(.*)/s', $this->get_zone_file($domain), $output_array);
    if (isset($output_array[1][0]) && !empty($output_array[1][0])) {
      return $output_array[1][0];
    }
    return;
  }

  function get_zone_header($domain) {
    return file_get_contents($this->ZONE_TEMPLATE);
  }

  function get_domain_summary($domain=false) {
    global $dom;
    if (empty($this->cache_domain_summary)) {
      $this->cache_domain_summary = $dom->get_domain_all_summary();
    }
    if ($domain) return $this->cache_domain_summary[$domain];
    else return $this->cache_domain_summary;
  }
  
  // Return a fully generated zone 
  function get_zone($domain) {
    global $L_FQDN, $L_NS1_HOSTNAME, $L_NS2_HOSTNAME, $L_DEFAULT_MX, $L_DEFAULT_SECONDARY_MX, $L_PUBLIC_IP;

    $zone='';
    $zone.=$this->get_zone_header($domain);
    $zone.=implode("\n",$this->conf_from_db($domain));
    $zone.="\n;;;HOOKED ENTRY\n";
    // FIXME ADD HOOKS opendkim, autoconfig toussa....
    $zone.="\n;;;END ALTERNC AUTOGENERATE CONFIGURATION\n";
    $zone.=$this->get_persistent($domain);

    // FIXME check those vars
    $zone = strtr($zone, array(
            "%%fqdn%%"=>"$L_FQDN",
            "%%ns1%%"=>"$L_NS1_HOSTNAME",
            "%%ns2%%"=>"$L_NS2_HOSTNAME",
            "%%DEFAULT_MX%%"=>"$L_DEFAULT_MX",
            "%%DEFAULT_SECONDARY_MX%%"=>"$L_DEFAULT_SECONDARY_MX",
            "@@fqdn@@"=>"$L_FQDN",
            "@@ns1@@"=>"$L_NS1_HOSTNAME",
            "@@ns2@@"=>"$L_NS2_HOSTNAME",
            "@@DEFAULT_MX@@"=>"$L_DEFAULT_MX",
            "@@DEFAULT_SECONDARY_MX@@"=>"$L_DEFAULT_SECONDARY_MX",
            "@@DOMAINE@@"=>"$domain",
            "@@SERIAL@@"=>$this->get_serial($domain),
            "@@PUBLIC_IP@@"=>"$L_PUBLIC_IP",
            "@@ZONETTL@@"=> $this->get_domain_summary($domain)['zonettl'],
          ));

    return $zone;
  }

  function reload_zone($domain) {
    exec($this->RNDC." reload ".escapeshellarg($domain));
  }

  // return true if zone is locked
  function is_locked($domain) {
    preg_match_all("/(\;\s*LOCKED:YES)/i", $this->get_zone($domain), $output_array);
    if (isset($output_array[1][0]) && !empty($output_array[1][0])) {
      return true;
    }
    return false;
  }  

  function save_zone($domain) {
    if ( $this->is_locked($domain)) return false;
 
    $file=$this->get_zone_file_uri($domain);
    file_put_contents($file, $this->get_zone($domain));
    chown($file, 'bind');
    chmod($file, 640);
    return true; // fixme add tests
  }

  function delete_zone($domain) {
    $file=$this->get_zone_file_uri($domain);
    if (file_exists($file)) {
      unlink($file);
    }
    return;
  }

  function reload_named() {
    $new_named_conf="// DO NOT EDIT\n// This file is generated by Alternc.\n// Every changes you'll make will be overwrited.\n";
    $tpl=file_get_contents($this->NAMED_TEMPLATE);
    foreach ($this->get_domain_summary() as $domain => $ds ) {
      if ( ! $ds['gesdns'] || strtoupper($ds['dns_action']) == 'DELETE' ) continue;
      $new_named_conf.=strtr($tpl, array("@@DOMAINE@@"=>$domain, "@@ZONE_FILE@@"=>$this->get_zone_file_uri($domain)));
    }
    $old_named_conf = @file_get_contents($this->NAMED_CONF);

    if ($old_named_conf != $new_named_conf ) {
      file_put_contents($this->NAMED_CONF,$new_named_conf);
      chown($this->NAMED_CONF, 'bind');
      chmod($this->NAMED_CONF, 640);
      exec($this->RNDC." reconfig");
    }
  }

  function regenerate_conf($all=false) {
    foreach ($this->get_domain_summary() as $domain => $ds ) {
      if ( ! $ds['gesdns'] ) continue;
      if ( strtoupper($ds['dns_action']) == 'DELETE' ) {
        $this->delete_zone($domain);
      }
      if ($all || strtoupper($ds['dns_action']) == 'UPDATE' ) {
        $this->save_zone($domain);
        $this->reload_zone($domain);
        // FIXME reload zone hooks
      }
    }
    $this->reload_named();
    // FIXME reload named hooks
  }

} // class

$bind = new m_bind_regenerate();

#echo $bind->get_zone('coin.fr');

echo $bind->regenerate_conf(true);


