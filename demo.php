<?php
include 'GopherStream.php';

/* Basically, we'll iterate through the last 10 articles on my blog (which is
 * available via Gopher), then grab the most recent one and display it. */

$base = 'gopher://xn--9bi.net';

$dir = opendir($base);
$articles = array();
if ($dir) {
	while (($article = readdir($dir)) !== false) {
		$articles[] = $article;
	}
	closedir($dir);

	if (PHP_SAPI == 'cli') {
		echo "ARTICLES:\n";
		echo implode("\n", $articles);
		echo "\n\n";
	}
	else {
		echo '<h1>Articles</h1><ul>';
		array_walk($articles, function ($item, $key) {
			echo '<li>'.htmlspecialchars($item).'</li>';
		});
		echo '</ul>';
	}
}
else {
	die('Unable to get directory listing.');
}

/* Loop through for the first link starting with /h (which is an article rather
 * than an index). */

$article = null;
foreach ($articles as $path) {
	if (substr($path, 0, 2) == '/h') {
		$article = $path;
		break;
	}
}

if ($article) {
	$content = file_get_contents($base.$article);
	echo $content;
}
else {
	die('Unable to find latest article.');
}

// vim: set cin ai ts=8 sw=8 noet:
