<?php

	define('MODE_THUMBNAIL', 'thumbnail');
	define('MODE_PLAYER',    'player');

	// Import configuration
	include('./config.php');

	if (!defined('EMBED_PLAYER')) {
		define('EMBED_PLAYER', false);
	}

	// Update .htaccess file if needed
	if (!file_exists('.htaccess') || (filemtime('config.php') > filemtime('.htaccess')))
	{
		$htaccess  = "<IfModule mod_rewrite.c>\n";
		$htaccess .= "	RewriteEngine On\n";
		$htaccess .= "	RewriteBase " . SCRIPT_PATH . "\n";
		$htaccess .= "	RewriteRule ^" . substr(SCRIPT_PATH, 1) . "index\.php$ - [L]\n";
		$htaccess .= "	RewriteCond %{REQUEST_FILENAME} !-f\n";
		$htaccess .= "	RewriteCond %{REQUEST_FILENAME} !-d\n";
		$htaccess .= "	RewriteRule . " . SCRIPT_PATH . "index.php [L]\n";
		$htaccess .= "</IfModule>\n";

		file_put_contents('.htaccess', $htaccess);
	}

	// Detect if rendering for the Facebook/Twitter agent or the end-user
	$isSocialBot = preg_match('/(facebookexternalhit|Twitterbot)/i', @$_SERVER['HTTP_USER_AGENT']);

	// Parse path
	$path = str_replace(SCRIPT_PATH, '', $_SERVER['REQUEST_URI']);

	// Get thumbnail
	if (preg_match('@^thumbnail/(.+)$@', $path, $matches)) {
		$id = $matches[1];
		$mode = MODE_THUMBNAIL;
	}
	// Get video player
	else {
		$id = $path;
		$mode = MODE_PLAYER;
	}

	if (!empty($id))
	{
		// Not Facebook parsing data: just redirect to the actual YouTube video
		if ((!$isSocialBot) && ($mode == MODE_PLAYER)) {
			header('Location: https://youtu.be/' . $id);
			exit(0);
		}

		// Load YouTube HTML
		$ytDoc = new DOMDocument();
		@$ytDoc->loadHTML('<?xml encoding="UTF-8">' . @file_get_contents('https://www.youtube.com/watch?v=' . $id));

		// Create DOM for Facebook
		$fbDoc = new DOMDocument();
		$fbDoc->loadHTML('<?xml encoding="UTF-8"><html><head></head><body></body></html>');

		// Grab video title
		foreach($ytDoc->getElementsByTagName('title') as $titleTag)
		{
			$fbDoc->getElementsByTagName('head')->item(0)->appendChild($fbDoc->importNode($titleTag, true));
		}

		// Grab meta properties
		$metaProperties = array();
		$basicProperties = array(
			'og:title'        => null,
			'og:description'  => null,
			'og:image'        => null,
			'og:video:url'    => null,
			'og:video:type'   => null,
			'og:video:width'  => null,
			'og:video:height' => null,
		);

		$ignoreProperties = array(
			'og:restrictions:age'
		);

		foreach($ytDoc->getElementsByTagName('meta') as $metaTag)
		{
			$property = $metaTag->getAttribute('property');

			// Remove secure URL to fool Facebook
			if ($property == 'og:video:secure_url')
				continue;

			if ($property && !in_array($property, $ignoreProperties))
			{
				$content = $metaTag->getAttribute('content');

				// Only deal with video basic properties once
				if (preg_match('/^og:video:/', $property) && array_key_exists($property, $basicProperties) && ($basicProperties[$property] !== null)) {
					continue;
				}

				// Add anything but OG tags
				if (!preg_match('/^og:/', $property))
					$metaProperties[] = array('property' => $property, 'content' => $content);

				if (array_key_exists($property, $basicProperties) && ($basicProperties[$property] === null)) {
					$basicProperties[$property] = $content;
				}
			}
		}

		// Get thumbnail image URL
		$thumbnailUrl = EMBED_PLAYER ? $basicProperties['og:image'] : SITE_URL . SCRIPT_PATH . 'thumbnail/' . $id;

		// Get canonical URL
		$canonicalUrl = SITE_URL . SCRIPT_PATH . $id;

		// Create replacement meta properties
		$metaReplacements = array(
			'og:site_name' => SITE_NAME,
			'og:url'       => $canonicalUrl,

			'twitter:card'          => 'summary_large_image',
			'twitter:title'         => $basicProperties['og:title'],
			'twitter:site'          => TWITTER,
			'twitter:description'   => $basicProperties['og:description'],
			'twitter:image'         => SITE_URL . SCRIPT_PATH . 'thumbnail/' . $id,
		);

		// Add embedded player card data if domain is whitelisted on Twitter
		if (TWITTER_CARD_WHITELISTED)
			$metaReplacements = array_merge($metaReplacements, array(
				'twitter:card'          => 'player',
				'twitter:player'        => 'https://www.youtube.com/embed/' . $id,
				'twitter:player:width'  => $basicProperties['og:video:width'],
				'twitter:player:height' => $basicProperties['og:video:height'],
				'twitter:image'         => $basicProperties['og:image'],
			));

		// Create META tags data

		$newMetaData = array(
			array(
				'name' => 'description',
				'content' => $basicProperties['og:description'],
			),
			array(
				'itemprop' => 'name',
				'content' => $basicProperties['og:title'],
			),
			array(
				'itemprop' => 'description',
				'content' => $basicProperties['og:description'],
			),
			array(
				'itemprop' => 'image',
				'content' => SITE_URL . SCRIPT_PATH . 'thumbnail/' . $id,
			),
		);

		// Add YouTube meta properties and perform replacements

		$metaPropertiesToReplace = $metaReplacements;

		foreach($metaProperties as $tagData)
		{
			// This property must be replaced
			if (array_key_exists($tagData['property'], $metaReplacements))
			{
				$tagData['content'] = $metaReplacements[$tagData['property']];
				unset($metaPropertiesToReplace[$tagData['property']]);
			}

			$newMetaData[] = $tagData;
		}

		// Add missing properties that have not been replaced (not found)

		foreach($metaPropertiesToReplace as $property => $content)
			$newMetaData[] = array(
				'property' => $property,
				'content'  => $content,
			);

		// Add safe OG tags
		$newMetaData = array_merge($newMetaData, array(
			array('property' => 'og:type', 'content' => EMBED_PLAYER ? 'video.other' : 'article'),
			array('property' => 'og:site_name', 'content' => preg_replace('@^https?://@', '', SITE_URL)),
			array('property' => 'og:title', 'content' => $basicProperties['og:title']),
			array('property' => 'og:description', 'content' => $basicProperties['og:description']),
			array('property' => 'og:image', 'content' => $thumbnailUrl)
		));

		if (EMBED_PLAYER) {
			$newMetaData = array_merge($newMetaData, array(
				array('property' => 'og:video:url', 'content' => 'http://www.youtube.com/v/' . $id . '?version=3'),
				array('property' => 'og:video:type', 'content' => 'application/x-shockwave-flash'),
				array('property' => 'og:video:width', 'content' => '560'),
				array('property' => 'og:video:height', 'content' => '349')
			));
		}

		switch ($mode) {

			case MODE_THUMBNAIL:
				// Generate thumbnail
				$thumbnailImageUrl = $basicProperties['og:image'];

				$thumbnailImage = @imagecreatefromjpeg($thumbnailImageUrl);
				if (!$thumbnailImage) {
					$thumbnailImage = imagecreatetruecolor(1920, 1080);
				}

				$buttonImage = imagecreatefrompng(dirname(__FILE__) . '/play-button.png');
				imagealphablending($thumbnailImage, true);
				imagealphablending($buttonImage, true);

				$tw = imagesx($thumbnailImage);
				$th = imagesy($thumbnailImage);

				$bw = min($tw, $th);
				$bh = $bw;

				$bx = ($tw - $bw) / 2;
				$by = ($th - $bh) / 2;

				imagecopyresampled(
					$thumbnailImage, $buttonImage,
					$bx, $by,
					0, 0,
					$bw, $bh,
					1080, 1080);

				ob_start();
				imagejpeg($thumbnailImage, null, 80);
				header('Content-type: image/jpeg');
				header('Content-length: ' . ob_get_length());
				ob_end_flush();

				exit(0);

				break;

			case MODE_PLAYER:
			default:

				// Generate player HTML

				$canonical = $fbDoc->createElement('link');
				$canonical->setAttribute('rel', 'canonical');
				$canonical->setAttribute('href', SITE_URL . SCRIPT_PATH . $id);
				$fbDoc->getElementsByTagName('head')->item(0)->appendChild($canonical);

				foreach($newMetaData as $metaData)
				{
					$meta = $fbDoc->createElement('meta');
					foreach($metaData as $k => $v)
						$meta->setAttribute($k, $v);

					$fbDoc->getElementsByTagName('head')->item(0)->appendChild($meta);
				}

				$fbDoc->getElementsByTagName('body')->item(0)->appendChild($fbDoc->createElement('script', 'window.location=\'https://youtu.be/' . $id . '\''));

				header('Content-type: text/html; charset=UTF-8');
				echo str_replace('<?xml encoding="UTF-8">', '', $fbDoc->saveHTML());

		}

		exit(0);
	}
?>
<!DOCTYPE html>
<html lang="fr">
	<head>
		<title>YouTube to Facebook</title>
		<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0" />

		<meta name="description" content="Embed YouTube Videos on Facebook">

		<meta itemprop="name" content="YouTube to Facebook" />
		<meta itemprop="url" content="<?php echo SITE_URL . SCRIPT_PATH ?>" />
		<meta itemprop="description" content="Embed YouTube videos on Facebook and Twitter" />
		<meta itemprop="image" content="<?php echo SITE_URL . SCRIPT_PATH ?>youtube-to-facebook.jpg" />

		<meta property="og:type" content="website" />
		<meta property="fb:admins" content="<?php echo FB_ADMINS ?>" />
		<meta property="og:title" content="YouTube to Facebook" />
		<meta property="og:url" content="<?php echo SITE_URL . SCRIPT_PATH ?>" />
		<meta property="og:description" content="Embed YouTube videos on Facebook" />
		<meta property="og:image" content="<?php echo SITE_URL . SCRIPT_PATH ?>youtube-to-facebook.jpg" />

		<meta property="twitter:card" content="summary_large_image" />
		<meta property="twitter:site" content="<?php echo TWITTER ?>" />
		<meta property="twitter:title" content="YouTube to Facebook" />
		<meta property="twitter:description" content="Embed YouTube videos on Twitter" />
		<meta property="twitter:image" content="<?php echo SITE_URL . SCRIPT_PATH ?>youtube-to-facebook.jpg" />
		<meta property="twitter:url" content="<?php echo SITE_URL . SCRIPT_PATH ?>" />

		<link href="https://fonts.googleapis.com/css?family=Roboto" rel="stylesheet">

		<style type="text/css">

			/* http://meyerweb.com/eric/tools/css/reset/
			   v2.0 | 20110126
			   License: none (public domain)
			*/

			html, body, div, span, applet, object, iframe,
			h1, h2, h3, h4, h5, h6, p, blockquote, pre,
			a, abbr, acronym, address, big, cite, code,
			del, dfn, em, img, ins, kbd, q, s, samp,
			small, strike, strong, sub, sup, tt, var,
			b, u, i, center,
			dl, dt, dd, ol, ul, li,
			fieldset, form, label, legend,
			table, caption, tbody, tfoot, thead, tr, th, td,
			article, aside, canvas, details, embed,
			figure, figcaption, footer, header, hgroup,
			menu, nav, output, ruby, section, summary,
			time, mark, audio, video {
				margin: 0;
				padding: 0;
				border: 0;
				font-size: 100%;
				font: inherit;
				vertical-align: baseline;
			}
			/* HTML5 display-role reset for older browsers */
			article, aside, details, figcaption, figure,
			footer, header, hgroup, menu, nav, section {
				display: block;
			}
			body {
				line-height: 1;
			}
			ol, ul {
				list-style: none;
			}
			blockquote, q {
				quotes: none;
			}
			blockquote:before, blockquote:after,
			q:before, q:after {
				content: '';
				content: none;
			}
			table {
				border-collapse: collapse;
				border-spacing: 0;
			}

			h1 {
				font-size: 2rem;
				font-weight: bold;
			}

			input, label, h1, div {
				width: 100%;
				display: block;
				margin: .5em 0;
			}

			input {
				max-width: 480px;
			}

			.copied {
				display: none;
				background-color: #008800;
				border-radius: 5px;
				padding: 5px;
				color: #FFFFFF;
				max-width: 480px;
				text-align: center;
			}

			body {
				padding: 1em;
				font-family: Roboto, sans-serif;
			}

		</style>

		<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>

		<script type="text/javascript">
			$(function() {

				// Paste URL on focus when empty
				$('#youtube_url').on('click', function(e) {
					if ($(this).val() == '') {
						paste(this);
					}
				});

				// Update Facebook URL
				$('#youtube_url').on('change keyup focusout paste', function(e) {
					update_url();
				});

				// Copy Facebook URL on focus
				$('#facebook_url').on('focus click', function(e) {
					select_all_and_copy(this);
				});
			});

			function youtube_parser(url) {
				var regExp = /^.*((youtu.be\/)|(v\/)|(\/u\/\w\/)|(embed\/)|(watch\?))\??v?=?([^#\&\?]*).*/;
				var match = url.match(regExp);
				return (match&&match[7].length==11)? match[7] : false;
			}

			function update_url() {
				var ytId = youtube_parser($('#youtube_url').val());

				if (ytId)
					var fbUrl = '<?php echo SITE_URL . SCRIPT_PATH ?>' + ytId;
				else
					var fbUrl = '';

				$('#facebook_url').val(fbUrl);
				$('.copied').hide();
			}

			function tooltip(el, msg) {
				$('.copied').html(msg).show().delay(1000).fadeOut();
			}

			// Copy and paste functions
			// http://www.seabreezecomputers.com/tips/copy2clipboard.htm

			function paste(el)
			{
				if (window.clipboardData) {
					// IE
					el.value = window.clipboardData.getData('Text');
					el.innerHTML = window.clipboardData.getData('Text');
				}
				else if (window.getSelection && document.createRange) {
					// non-IE
					if (el.tagName.match(/textarea|input/i) && el.value.length < 1)
						el.value = " "; // iOS needs element not to be empty to select it and pop up 'paste' button
					else if (el.innerHTML.length < 1)
						el.innerHTML = "&nbsp;"; // iOS needs element not to be empty to select it and pop up 'paste' button
					var editable = el.contentEditable; // Record contentEditable status of element
					var readOnly = el.readOnly; // Record readOnly status of element
					el.contentEditable = true; // iOS will only select text on non-form elements if contentEditable = true;
					el.readOnly = false; // iOS will not select in a read only form element
					var range = document.createRange();
					range.selectNodeContents(el);
					var sel = window.getSelection();
					sel.removeAllRanges();
					sel.addRange(range);
					if (el.nodeName == "TEXTAREA" || el.nodeName == "INPUT")
						el.select(); // Firefox will only select a form element with select()
					if (el.setSelectionRange && navigator.userAgent.match(/ipad|ipod|iphone/i))
						el.setSelectionRange(0, 999999); // iOS only selects "form" elements with SelectionRange
					if (document.queryCommandSupported("paste"))
					{
						var successful = document.execCommand('Paste');
						if (successful) tooltip(el, "Pasted.");
						else
						{
							if (navigator.userAgent.match(/android/i) && navigator.userAgent.match(/chrome/i))
							{
								tooltip(el, "Click blue tab then click Paste");

									if (el.tagName.match(/textarea|input/i))
									{
										el.value = " "; el.focus();
										el.setSelectionRange(0, 0);
									}
									else
										el.innerHTML = "";

							}
							else
								tooltip(el, "Press CTRL-V to paste");
						}
					}
					else
					{
						if (!navigator.userAgent.match(/ipad|ipod|iphone|android|silk/i))
							tooltip(el, "Press CTRL-V to paste");
					}
					el.contentEditable = editable; // Restore previous contentEditable status
					el.readOnly = readOnly; // Restore previous readOnly status
				}
			}

			function select_all_and_copy(el)
			{
				// Copy textarea, pre, div, etc.
				if (document.body.createTextRange) {
					// IE
					var textRange = document.body.createTextRange();
					textRange.moveToElementText(el);
					textRange.select();
					textRange.execCommand("Copy");
					tooltip(el, "Copied!");
				}
				else if (window.getSelection && document.createRange) {
					// non-IE
					var editable = el.contentEditable; // Record contentEditable status of element
					var readOnly = el.readOnly; // Record readOnly status of element
					el.contentEditable = true; // iOS will only select text on non-form elements if contentEditable = true;
					el.readOnly = false; // iOS will not select in a read only form element
					var range = document.createRange();
					range.selectNodeContents(el);
					var sel = window.getSelection();
					sel.removeAllRanges();
					sel.addRange(range); // Does not work for Firefox if a textarea or input
					if (el.nodeName == "TEXTAREA" || el.nodeName == "INPUT")
						el.select(); // Firefox will only select a form element with select()
					if (el.setSelectionRange && navigator.userAgent.match(/ipad|ipod|iphone/i))
						el.setSelectionRange(0, 999999); // iOS only selects "form" elements with SelectionRange
					el.contentEditable = editable; // Restore previous contentEditable status
					el.readOnly = readOnly; // Restore previous readOnly status
					if (document.queryCommandSupported("copy"))
					{
						var successful = document.execCommand('copy');
						if (successful) tooltip(el, "Copied to clipboard.");
						else tooltip(el, "Press CTRL+C to copy");
					}
					else
					{
						if (!navigator.userAgent.match(/ipad|ipod|iphone|android|silk/i))
							tooltip(el, "Press CTRL+C to copy");
					}
				}
			} // end function select_all_and_copy(el)

		</script>

	</head>
	<body dir="ltr" class="" id="body">
		<h1>YouTube to Facebook</h1>

		<label for="youtube_url">YouTube URL</label>
		<input type="text" name="youtube_url" id="youtube_url" placeholder="Paste YouTube URL here">

		<label for="facebook_url">Facebook URL</label>
		<input type="text" name="facebook_url" id="facebook_url" placeholder="Copy Facebook URL" readonly>
		<div class="copied">Copied!</div>
	</body>
</html>