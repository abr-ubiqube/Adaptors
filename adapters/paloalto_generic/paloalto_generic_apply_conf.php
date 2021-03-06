<?php

// Transfer the configuration file on the router
// First try to use SCP then TFTP
require_once 'smsd/sms_common.php';
require_once load_once('paloalto_generic', 'paloalto_generic_connect.php');
require_once "$db_objects";

/**
 * Apply the configuration using tftp (failover line by line)
 * @param string  $configuration        configuration to apply
 * @param boolean $copy_to_startup      copy in startup-config+reboot instead of running-config+write mem
 */
function paloalto_generic_apply_conf($configuration)
{
    global $sdid;
    global $sms_sd_ctx;
    global $sendexpect_result;
    global $apply_errors;
    global $operation;
    global $SD;

    // Save the configuration applied on the router
    save_result_file($configuration, 'conf.applied');
    $SMS_OUTPUT_BUF = '';
    $api_return_value = "";

  $apikey_msg = "API Key is successfully set";
  $deactivate_msg = 'Successfully deactivated old keys';
    $line = get_one_line($configuration);
    while ($line !== false) {
        $line = trim($line);
        if (!empty($line)) {
            $res = sendexpectone(__FILE__ . ':' . __LINE__, $sms_sd_ctx, $line, '/response');
            if (trim($res['status']) !== 'success' && (trim($res['code']) !== '19' && trim($res['code']) !== '20')
                && !strstr($res, $apikey_msg) && !strstr($res, $deactivate_msg) )
            {
                $line = urldecode($line);
                $msg = res_get_msg($res);
                $SMS_OUTPUT_BUF .= "{$line}\n\n{$msg}\n";
            }
            else
            {
                $msg = res_get_msg($res);
            }
            $api_return_value = $msg;
    }
    $line = get_one_line($configuration);
  }

  if (! is_manual_commit())
  {
    $ret = commit();
    $api_return_value = $ret;

    if (!empty($SMS_OUTPUT_BUF))
    {
      $SMS_OUTPUT_BUF .= $ret;
    }
  }

  save_result_file($SMS_OUTPUT_BUF, "conf.error");
  if (!empty($SMS_OUTPUT_BUF)) // we have detected an error cf above
  {
    // returning an error code causes the framework to use $SMS_OUTPUT_BUF
    // for the API return value (json.message) and $SMS_RETURN_BUF is ignored
    sms_log_error(__FILE__ . ':' . __LINE__ . ": [[!!! $SMS_OUTPUT_BUF !!!]]\n");
    return ERR_SD_CMDFAILED;
  }

  // set API return value (json.message) for success cases
  $SMS_RETURN_BUF = $api_return_value;

  return SMS_OK;
}

function res_get_msg($res)
{
  $msg = "";
  if (!empty($res->msg->line->line)) {
      $msg = (String)$res->msg->line->line;
  } elseif (!empty($res->msg->line)) {
      $msg = (String)$res->msg->line;
  } elseif (!empty($res->msg)) {
      $msg = (String)$res->msg;
  } elseif (!empty($res->result->msg)) {
      $msg = (String)$res->result->msg;
  }
  return $msg;
}

function is_manual_commit()
{
  $net_profile = get_network_profile();
  $sd = &$net_profile->SD;

  return filter_var(
    $sd->SD_CONFIGVAR_list['CONFIGURATION_MANUAL_COMMIT']->VAR_VALUE,
    FILTER_VALIDATE_BOOLEAN
  );
}

function commit()
{
  global $SD;
  global $sms_sd_ctx;

  // commit
  if ($SD->MOD_ID === 136)
  {
    $cmd = "<commit><partial><vsys><member>{$SD->SD_HOSTNAME}</member></vsys></partial></commit>";
  }
  else
  {
    $cmd = "<commit></commit>";
  }
  $result = sendexpectone(__FILE__ . ':' . __LINE__, $sms_sd_ctx, 'type=commit&cmd='.urlencode($cmd));
  if (!empty($result->result) && !empty($result->result->job))
  {
    $job = $result->result->job;
    $net_pf = get_network_profile();
    $sd =&$net_pf->SD;
    $palo_retry_configured_limit = $sd->SD_CONFIGVAR_list['palo_retry_show_limit']->VAR_VALUE;
    $palo_retry_show_limit = $palo_retry_configured_limit;
    if(empty($palo_retry_show_limit)) {
      $palo_retry_show_limit = 30; //default
    }
    sms_log_info("palo_retry_show_limit: " . $palo_retry_show_limit);
    $last_result = null;
    do
    {
      if ($palo_retry_show_limit <= 0)
      {
        sms_log_error(__FILE__ . ':' . __LINE__ . ' : Giving up after ' . $palo_retry_configured_limit . ' times (no status FIN received)');
        break;
      }
      $palo_retry_show_limit--;

                    sleep(2);
      try {
                    $result = sendexpectone(__FILE__ . ':' . __LINE__, $sms_sd_ctx, 'type=op&cmd='.urlencode("<show><jobs><id>{$job}</id></jobs></show>"));
                    if (!empty($operation) && $result->result->job->status == 'ACT') {
                        status_progress("progress {$result->result->job->progress}%", $operation);
                    }
        $last_result = $result; //store the response
      } catch (Exception | Error $e) {
        sms_log_info($e->getMessage());
          if(!empty($last_result)) {
            //check the warning contents of last show response
            $warnings = $last_result->result->job->warnings;
            if(!empty($warnings)) {
              $line = $warnings->line;
              $expected_warning = "Web server will be restarted";
              if (strpos($line, $expected_warning)  !== false ) {
                $result = $last_result; //set the last show response as result
                continue;
              }
            }
          }
        throw $e;
      }
    } while ($result->result->job->status != 'FIN');

    return $result->result->job->asXml();
  }
}

function send_configuration_file($configuration)
{
    global $sdid;
    global $sms_sd_ctx;

    save_result_file($configuration, 'conf.applied');
    save_result_file($configuration, 'conf.xml');

    $filepath = "{$_SERVER['GENERATED_CONF_BASE']}/$sdid/conf.xml";
    return $sms_sd_ctx->send_file(__FILE__ . ':' . __LINE__, $filepath);
}


?>
