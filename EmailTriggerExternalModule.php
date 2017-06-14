<?php
namespace ExternalModules;
require_once dirname(__FILE__) . '/../../external_modules/classes/ExternalModules.php';
require_once APP_PATH_DOCROOT.'Classes/Files.php';
require_once 'vendor/autoload.php';
//require_once(dirname(dirname(__DIR__))."/plugins/Core/bootstrap.php");
//global $Core;
//
//$Core->Libraries(array('Passthru'));



class EmailTriggerExternalModule extends AbstractExternalModule
{

	function hook_survey_complete ($project_id,$record = NULL,$instrument,$event_id){
        $data = \REDCap::getData($project_id);
        if(isset($project_id)){
            #Form Complete
            $forms_name = $this->getProjectSetting("form-name",$project_id);
            if(!empty($forms_name) && $record != NULL){
                foreach ($forms_name as $id => $form){
                    $sql="SELECT s.form_name FROM redcap_surveys_participants as sp LEFT JOIN redcap_surveys s ON (sp.survey_id = s.survey_id ) where s.project_id =".$project_id." AND sp.hash='".$_REQUEST['s']."'";
                    $q = db_query($sql);

                    if($error = db_error()){
                        die($sql.': '.$error);
                    }

                    while($row = db_fetch_assoc($q)){
                        if ($row['form_name'] == $form) {
                            $email_sent = $this->getProjectSetting("email-sent",$project_id);
                            $email_timestamp_sent = $this->getProjectSetting("email-timestamp-sent",$project_id);
                            $this->sendEmailAlert($project_id, $id, $data, $record,$email_sent,$email_timestamp_sent);
                        }
                    }

                }
            }
        }
    }

	function hook_save_record ($project_id,$record = NULL,$instrument,$event_id){
		$data = \REDCap::getData($project_id);
		if(isset($project_id)){
			#Form Complete
			$forms_name = $this->getProjectSetting("form-name",$project_id);
			if(!empty($forms_name) && $record != NULL){
                foreach ($forms_name as $id => $form){
//                    echo "Survey: ".$form."<br/>";
//                    echo "Record: ".$record."<br/>";
//                    $surveylink = \Plugin\Passthru::PassthruToSurvey($record,$form);

                    if($data[$record][$event_id][$form.'_complete'] == '2'){
                        if ($_REQUEST['page'] == $form) {
                            $email_sent = $this->getProjectSetting("email-sent",$project_id);
                            $email_timestamp_sent = $this->getProjectSetting("email-timestamp-sent",$project_id);
                            $this->sendEmailAlert($project_id, $id, $data, $record,$email_sent,$email_timestamp_sent);
                        }
                    }
                }
			}
		}
	}

    function sendEmailAlert($project_id, $id, $data, $record,$email_sent,$email_timestamp_sent){
        $email_repetitive = $this->getProjectSetting("email-repetitive",$project_id)[$id];
        $email_timestamp = $this->getProjectSetting("email-timestamp",$project_id)[$id];
        if(($email_repetitive == "1") || ($email_repetitive == '0' && $email_sent[$id] == "0")) {
            $email_condition = $this->getProjectSetting("email-condition", $project_id)[$id];
            //If the condition is met or if we don't have any, we send the email
            if ((!empty($email_condition) && \LogicTester::isValid($email_condition) && \LogicTester::apply($email_condition, $data[$record], null, false)) || empty($email_condition)) {
                $email_to = $this->getProjectSetting("email-to", $project_id)[$id];
                $email_cc = $this->getProjectSetting("email-cc", $project_id)[$id];
                $email_subject = $this->getProjectSetting("email-subject", $project_id)[$id];
                $email_text = $this->getProjectSetting("email-text", $project_id)[$id];
                $email_attachment_variable = $this->getProjectSetting("email-attachment-variable", $project_id)[$id];
                $datapipe_var = $this->getProjectSetting("datapipe_var", $project_id);
                $datapipe_enable = $this->getProjectSetting("datapipe_enable", $project_id);
                $datapipeEmail_enable = $this->getProjectSetting("datapipeEmail_enable", $project_id);
                $datapipeEmail_var = $this->getProjectSetting("datapipeEmail_var", $project_id);



                //Data piping
                if (!empty($datapipe_var) && $datapipe_enable == 'on') {
                    $email_form_var = preg_split("/[;,]+/", $datapipe_var);
                    foreach ($email_form_var as $var) {
                        if (\LogicTester::isValid($var)) {
                            $email_text = str_replace($var, \LogicTester::apply($var, $data[$record], null, true), $email_text);
                            $email_subject = str_replace($var, \LogicTester::apply($var, $data[$record], null, true), $email_subject);
                        }
                    }
                }

                $mail = new \PHPMailer;

                //Email Addresses
                if ($datapipeEmail_enable == 'on') {
                    $email_form_var = explode("\n", $datapipeEmail_var);

                    $emailsTo = preg_split("/[;,]+/", $email_to);
                    $emailsCC = preg_split("/[;,]+/", $email_cc);
                    $mail = $this->fill_emails($mail,$emailsTo, $email_form_var, $data[$record], 'to',$project_id);
                    $mail = $this->fill_emails($mail,$emailsCC, $email_form_var, $data[$record], 'cc',$project_id);

                }else{
                    $email_to_ok = $this->check_email ($email_to,$project_id);
                    $email_cc_ok = $this->check_email ($email_cc,$project_id);

                    if(!empty($email_to_ok)) {
                        foreach ($email_to_ok as $email) {
                            $mail->addAddress($email);
                        }
                    }

                    if(!empty($email_cc_ok)){
                        foreach ($email_cc_ok as $email) {
                            $mail->AddCC($email);
                        }
                    }
                }

                //Embedded images
                preg_match_all('/src=[\"\'](.+?)[\"\'].*?/i',$email_text, $result);
                $result = array_unique($result[1]);
                foreach ($result as $img_src){
                    preg_match_all('/(?<=file=)\\s*([0-9]+)\\s*/',$img_src, $result_img);
                    $edoc = array_unique($result_img[1]);

                    if(!empty($edoc[0])){
                        $sql="SELECT stored_name FROM redcap_edocs_metadata WHERE doc_id=".$edoc[0];
                        $q = db_query($sql);

                        if($error = db_error()){
                            die($sql.': '.$error);
                        }

                        while($row = db_fetch_assoc($q)){
                            $path = EDOC_PATH.$row['stored_name'];
                            $src = "cid:".$edoc;

                            $email_text = str_replace($img_src,$src,$email_text);
                            $mail->AddEmbeddedImage($path,$edoc);
                        }
                    }
                }

                $mail->Subject = $email_subject;
                $mail->IsHTML(true);
                $mail->Body = $email_text;

                //Attachments
                for($i=1; $i<6 ; $i++){
                    $edoc = $this->getProjectSetting("email-attachment".$i,$project_id)[$id];
                    if(!empty($edoc)){
                        $sql="SELECT stored_name FROM redcap_edocs_metadata WHERE doc_id=".$edoc;
                        $q = db_query($sql);

                        if($error = db_error()){
                            die($sql.': '.$error);
                        }

                        while($row = db_fetch_assoc($q)){
                            $mail->AddAttachment(EDOC_PATH.$row['stored_name']);
                        }
                    }
                }
                //Attchment from RedCap variable
                if(!empty($email_attachment_variable)){
                    $var = preg_split("/[;,]+/", $email_attachment_variable);
                    foreach ($var as $attachment) {
                        if(\LogicTester::isValid(trim($attachment))) {
                            $edoc = \LogicTester::apply(trim($attachment), $data[$record], null, true);
                            $sql = "SELECT stored_name FROM redcap_edocs_metadata WHERE doc_id=" . $edoc;
                            $q = db_query($sql);

                            if ($error = db_error()) {
                                die($sql . ': ' . $error);
                            }

                            while ($row = db_fetch_assoc($q)) {
                                $mail->AddAttachment(EDOC_PATH . $row['stored_name']);
                            }
                        }
                    }
                }


                //DKIM to make sure the email does not go into spam folder
                $privatekeyfile = 'dkim_private.key';
                //Make a new key pair
                //(2048 bits is the recommended minimum key length -
                //gmail won't accept less than 1024 bits)
                $pk = openssl_pkey_new(
                    array(
                        'private_key_bits' => 2048,
                        'private_key_type' => OPENSSL_KEYTYPE_RSA
                    )
                );
                openssl_pkey_export_to_file($pk, $privatekeyfile);
                $mail->DKIM_private = $privatekeyfile;
                $mail->DKIM_selector = 'PHPMailer';
                $mail->DKIM_passphrase = ''; //key is not encrypted
                if (!$mail->send()) {
                    \REDCap::email('eva.bascompte.moragas@vanderbilt.edu', 'noreply@vanderbilt.edu', "Mailer Error", "Mailer Error:".$mail->ErrorInfo." in project ".$project_id);
                } else {
                    $email_sent[$id] = "1";
                    if($email_timestamp == "1"){
                        $email_timestamp_sent[$id] = date('Y-m-d H:i:s');
                        $this->setProjectSetting('email-timestamp-sent', $email_timestamp_sent, $project_id) ;
                    }
                    $this->setProjectSetting('email-sent', $email_sent, $project_id) ;

                }
                unlink($privatekeyfile);
                // Clear all addresses and attachments for next loop
                $mail->clearAddresses();
                $mail->clearAttachments();
            }
        }

    }

    function fill_emails($mail, $emailsTo, $email_form_var, $data, $option, $project_id){
        foreach ($emailsTo as $email){
            foreach ($email_form_var as $email_var) {
                $var = preg_split("/[;,]+/", $email_var);
                if(!empty($email)) {
                    if (\LogicTester::isValid($var[0])) {
                        $email_redcap = \LogicTester::apply($var[0], $data, null, true);
                        if (!empty($email_redcap) && strpos($email, $var[0]) !== false) {
                            $mail = $this->check_single_email($mail,$email_redcap,$option,$project_id);
                        } else {
                            $mail = $this->check_single_email($mail,$email,$option,$project_id);
                        }
                    } else {
                        $mail = $this->check_single_email($mail,$email,$option,$project_id);
                    }
                }
            }
        }
        return $mail;
    }

    function check_single_email($mail,$email, $option, $project_id){
        if(filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if($option == "to"){
                $mail->addAddress($email);
            }else if($option == "cc"){
                $mail->addCC($email);
            }
        }else{
            \REDCap::email('eva.bascompte.moragas@vanderbilt.edu', 'noreply@vanderbilt.edu', "Wrong recipient", "The email ".$email." in the project ".$project_id.", do not exist");
        }
        return $mail;
    }

    /**
     * Function that checks if the emails are valid and sends an error email in case there's an error
     * @param $emails
     * @param $project_id
     * @return array|string
     */
    function check_email($emails, $project_id){
        $email_list = array();
        $email_list_error = array();
        $emails = preg_split("/[;,]+/", $emails);
        foreach ($emails as $email){
            if(!empty($email)){
                if(filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    //VALID
                    array_push($email_list,$email);
                }else{
                    array_push($email_list_error,$email);

                }
            }
        }
        if(!empty($email_list_error)){
            //if error send email to datacore@vanderbilt.edu
            \REDCap::email('eva.bascompte.moragas@vanderbilt.edu', 'noreply@vanderbilt.edu', "Wrong recipient", "The email/s ".implode(",",$email_list_error)." in the project ".$project_id.", do not exist");
        }
        return $email_list;
    }
}



