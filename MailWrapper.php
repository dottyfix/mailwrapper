<?php
namespace Dottyfix\Tools\MailWrapper;
use Dottyfix\CurlHelper;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use IvoPetkov\HTML5DOMDocument;

class MailWrapper {
	
	static function isBanned($email) {
		return false;
	}
	
	static function applyLayoutTpl($out, $data, $type) {
		$f = $GLOBALS['tplDir'].'mails/'.'_layout-'.$type.'.tpl';
		$c = $GLOBALS['tplCacheDir'].'mails/'.'_layout-'.$type.'.php';
		if(!file_exists($f))
			return $out;
		
		$data['CONTENT'] = $out;
		
		$phpStr = \LightnCandy\LightnCandy::compile(file_get_contents( $f ), $GLOBALS['LCConfig']);
		
		file_put_contents($c, '<?php ' . $phpStr . '?>');
		$renderer = include($c);
		return $renderer($data);
	}
	
	static function mailTpl($tpl, &$data, $type = 'html') {
		
		$debug = isset($_GET['debug']);
		
		$f = $GLOBALS['tplDir'].'mails/'.$tpl.'.tpl';
		$c = $GLOBALS['tplCacheDir'].'mails/'.$tpl.'.php';
		
		if(!file_exists($f))
			return false;
		
		$phpStr = \LightnCandy\LightnCandy::compile(file_get_contents( $f ), $GLOBALS['LCConfig']);
		// Save the compiled PHP code into a php file
		file_put_contents($c, '<?php ' . $phpStr . '?>');
		$renderer = include($c);
		$out = $renderer($data);
		
		$out = self::applyLayoutTpl($out, $data, $type);
		
		if($type == 'html') {
			
			$dom = new HTML5DOMDocument();
			$dom->loadHTML($out);
			$css = '';

			foreach($dom->querySelectorAll('link[href], style') as $styleElement) {
				if($styleElement->tagName == 'link' and $styleElement->getAttribute('rel') == 'stylesheet')
					$css .= file_get_contents($GLOBALS['tplDir'].'mails/'.$styleElement->getAttribute('href'));
				elseif($styleElement->tagName == 'style')
					$css .= $styleElement->innerHTML;
			}
			
			foreach($dom->querySelectorAll('img[src]') as $imgElement) {
/*
				$src = $imgElement->getAttribute('data-src');
				$srcDirectory = $GLOBALS['imgDir'];
				$cachePath = realpath($GLOBALS['CacheDir'].'img/').'/';
				$img = \Img::router($srcDirectory, '/img.php?', $cachePath, $src);
				$imgElement->setAttribute('src', 'data:image/' . $img['ext'] . ';base64,' . base64_encode(file_get_contents($img['target'])) );
*/
				$src = $imgElement->getAttribute('src');
				if(strpos($src, '/') === 0)
					$imgElement->setAttribute('src', URL_BASE_ABS.$src);
			}
			
			$out = $dom->saveHTML();
			
			//$css = file_get_contents($GLOBALS['tplDir'].'mails/style.css');
			
			if($debug) {
				die($out."<style>$css</style>");
			}
			$cssToInlineStyles = new \TijsVerkoyen\CssToInlineStyles\CssToInlineStyles();
			$out = $cssToInlineStyles->convert(
				$out,
				$css
			);
		}
		
		return $out;
	}

	static function ByPHPMailer($datas, $htmlContent, $textContent) {
		$debug = false;
		//Create an instance; passing `true` enables exceptions
		$mail = new PHPMailer(true);

		try {
			//Encodage
			$mail->CharSet = "UTF-8";
			$mail->Encoding = 'base64';
			
			//Server settings
			if($debug)
				$mail->SMTPDebug = SMTP::DEBUG_SERVER;               //Enable verbose debug output

			$mail->isSMTP();                                         //Send using SMTP
			$mail->Host       = MAIL_SMTP_HOST;                      //Set the SMTP server to send through
			$mail->SMTPAuth   = MAIL_SMTP_AUTH;                      //Enable SMTP authentication
			$mail->Username   = MAIL_SMTP_LOGIN;                     //SMTP username
			$mail->Password   = MAIL_SMTP_PASSWORD;                  //SMTP password
			
			if(MAIL_SMTP_ENCRYPTION == 'SMTPS')
				$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
			elseif(MAIL_SMTP_ENCRYPTION == 'STARTTLS')
				$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
			
			$mail->Port = MAIL_SMTP_PORT;                                    //TCP port to connect to; use 587 if you have set `SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS`

			$mail->setFrom(MAIL_FROM_EMAIL, defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : '');
			if(isset($datas['replyTo']))
				$mail->addReplyTo($datas['replyTo']['email'], isset($datas['replyTo']['name']) ? $datas['replyTo']['name'] : '');

			//Recipients
			if(isset($datas['to']))
				foreach($datas['to'] as $m)
					$mail->addAddress($m['email'], isset($m['name']) ? $m['name'] : '');     //Add a recipient
			if(isset($datas['cc']))
				foreach($datas['cc'] as $m)
					$mail->addCC($m['email'], isset($m['name']) ? $m['name'] : '');     //Add a recipient
			if(isset($datas['bcc']))
				foreach($datas['bcc'] as $m)
					$mail->addBCC($m['email'], isset($m['name']) ? $m['name'] : '');     //Add a recipient
			
			if($debug)
				$mail->addBCC('flavien.guillon@gmail.com');

			//Attachments
			/*
			if(isset($datas['images']))
				foreach($datas['images'] as $img) {
					$name = $img['cid'].'.'.$img['ext'];
					$mail->AddEmbeddedImage($img['target'], $img['cid'], $name);
				}
			*/
			
			//$mail->addAttachment('/var/tmp/file.tar.gz');         //Add attachments
			//$mail->addAttachment('/tmp/image.jpg', 'new.jpg');    //Optional name

			//Content
			$mail->isHTML(true);                                  //Set email format to HTML
			$mail->Subject = $datas['subject'];
			$mail->Body    = $htmlContent;
			$mail->AltBody = $textContent;

			$mail->send();
			return true;
		} catch (Exception $e) {
			err("Impossible d'envoyer le message. Error: {$mail->ErrorInfo}");
		}
	}

	static function sendMail($datas) {
		$brevoParams = [];
		
		if(!isset($datas['sender']))
			$datas['sender'] = ['email' => MAIL_FROM_EMAIL, 'email' => defined(MAIL_FROM_NAME) ? MAIL_FROM_NAME : ''];
		
		foreach($datas as $k => $v)
			if(in_array($k, ['sender', 'to', 'bcc', 'cc', 'htmlContent', 'textContent', 'subject', 'replyTo', 'attachment', 'headers', 'params', 'messageVersions', 'tags', 'scheduledAt', 'batchId']))	// https://developers.brevo.com/reference/sendtransacemail
				$brevoParams[$k] = $v;
		
		/*
		$images = [];
		if(isset($datas['images']))
			foreach($datas['images'] as $cid => $img) {
				$name = $cid.'.'.$img['ext'];
				if(file_exists($img['target']))
					if('brevo' == MAIL_METHOD)
						$images[$name] = [
							'src' => 'data:image/' . $type . ';base64,' . base64_encode(file_get_contents($img['target'])),
						];
					elseif('smtp' == MAIL_METHOD)
						$images[$name] = [
							'src' => 'cid:'.$cid,
							'cid' => $cid,
							'target' => $img['target'],
							'ext' => $img['ext'],
						];
				
				//$mail->AddEmbeddedImage($img['target'], $cid, $name);
			}
		*/
		
		if(isset($datas['tpl'])) {
			if($htmlContent = self::mailTpl($datas['tpl'].'-html', $datas))
				$brevoParams['htmlContent'] = $htmlContent;
			if($textContent = self::mailTpl($datas['tpl'].'-text', $datas))
				$brevoParams['textContent'] = $textContent;
		}
		/*
		$images = [];
		if(isset($datas['images']))
			foreach($datas['images'] as $cid => $img) {
				$srcDirectory = $GLOBALS['imgDir'];
				$cachePath = realpath($GLOBALS['CacheDir'].'img/').'/';
				$images[$cid] = Img::router($srcDirectory, '/img.php?', $cachePath, $query);
			}
		*/
		
		$headers = array(
			'From' => MAIL_FROM_EMAIL,
			'Reply-To' => $datas['sender']['email'],
			'X-Mailer' => 'PHP/' . phpversion()
		);
		
		$body = (isset($htmlContent) ? $htmlContent : $textContent);
		
		if('smtp' == MAIL_METHOD)
			return self::ByPHPMailer($datas, $htmlContent, $textContent);
		if('brevo' == MAIL_METHOD) {
			$ch = new CurlHelper('https://api.brevo.com/v3/', BREVO_API_KEY);
			return $ch->exec('smtp/email', $brevoParams, 'post');
		}
		return mail($datas['to']['email'], $datas['subject'], $body, $headers);
		
	}
	
}
