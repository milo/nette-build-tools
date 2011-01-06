<?php

/**
 * Makefile for building Nette Framework.
 *
 * Call task 'main' to build a full release.
 * The release built will be stored in 'dist' directory.
*/

require 'tools/Nette/loader.php';
use Nette\Finder;


// add custom tasks
require 'tasks/apiGen.php';
require 'tasks/git.php';
require 'tasks/minify.php';
require 'tasks/minifyJs.php';
require 'tasks/netteLoader.php';
require 'tasks/convert52.php';
require 'tasks/convert53.php';
require 'tasks/php.php';
require 'tasks/zip.php';


// configure tasks
$project->gitExecutable = 'C:\Program Files\Git\bin\git.exe';
$project->phpExecutable = realpath('tools/PHP-5.3/php.exe');
$project->php52Executable = realpath('tools/PHP-5.2/php.exe');
$project->apiGenExecutable = realpath('tools/apigen/apigen.php');
$project->zipExecutable = realpath('tools/7zip/7z.exe');
$project->compilerExecutable = realpath('tools/Google-Closure-Compiler/compiler.jar');


$project->main = function($branch = 'master', $label = '2.0dev', $tag = NULL) use ($project) {
	$project->log("Building {$label}");

	$dir53 = "NetteFramework-{$label}-PHP5.3";
	$dir52p = "NetteFramework-{$label}-PHP5.2";
	$dir52n = "NetteFramework-{$label}-PHP5.2-nonprefix";
	$distDir = "dist";


	// export from Git
	$project->delete($dir53);
	$project->gitClone('git://github.com/nette/nette.git', $branch, $dir53);
	if ($branch === 'v0.9.x') {
		// 3rdParty
		$project->gitClone('git://github.com/nette/dibi.git', 'master', "$dir53/3rdParty/dibi");
		$project->write("$dir53/3rdParty/dibi/netterobots.txt", 'Disallow: /dibi-minified');
	} else {
		$project->gitClone('git://github.com/nette/examples.git', $branch, "$dir53/examples");
		$project->gitClone('git://github.com/nette/sandbox.git', $branch, "$dir53/sandbox");
		$project->gitClone('git://github.com/nette/tools.git', $branch, "$dir53/tools");
	}

	if (isset($tag)) {
		$project->git("--work-tree $dir53 checkout $tag", $dir53);
	}

	if (PHP_OS === 'WINNT') {
		$project->exec("attrib -H $dir53\.htaccess* /s /d");
		$project->exec("attrib -R $dir53\* /s /d");
	}


	// create history.txt
	$project->git("log -n 500 --pretty=\"%cd (%h): %s\" --date-order --date=short > $dir53/history.txt", $dir53);


	// expand $WCREV$ and $WCDATE$
	$wcrev = $project->git('log -n 1 --pretty="%h"', $dir53);
	$wcdate = $project->git('log -n 1 --pretty="%cd" --date=short', $dir53);
	foreach (Finder::findFiles('*.php', '*.txt')->from($dir53)->exclude('3rdParty') as $file) {
		$project->replace($file, array(
			'#\$WCREV\$#' => $wcrev,
			'#\$WCDATE\$#' => $wcdate,
		));
	}


	// remove git files
	foreach (Finder::findDirectories(".git")->from($dir53)->childFirst() as $file) {
		$project->delete($file);
	}
	foreach (Finder::findFiles(".git*")->from($dir53) as $file) {
		$project->delete($file);
	}


	// build specific packages
	$project->delete($dir52p);
	$project->copy($dir53, $dir52p);
	$project->delete($dir52n);
	$project->copy($dir53, $dir52n);

	// build 5.3 package
	$project->log("Building 5.3 package");
	foreach (Finder::findFiles('*.php', '*.phpt', '*.inc', '*.phtml', '*.latte')->from($dir53)->exclude('www/adminer') as $file) {
		$project->convert53($file);
	}
	$classes = $project->netteLoader("$dir53/Nette");

	// build 5.2 prefix package
	$project->log("Building 5.2 prefixed package");
	foreach (Finder::findFiles('*.php', '*.phpt', '*.inc', '*.phtml', '*.latte')->from($dir52p)->exclude('www/adminer') as $file) {
		$project->convert52($file, TRUE, $classes);
	}
	$project->netteLoader("$dir52p/Nette");

	// build 5.2 nonprefix package
	$project->log("Building 5.2-nonprefix package");
	foreach (Finder::findFiles('*.php', '*.phpt', '*.inc', '*.phtml', '*.latte')->from($dir52n)->exclude('www/adminer') as $file) {
		$project->convert52($file, FALSE);
	}
	$project->netteLoader("$dir52n/Nette");


	// shrink JS & CSS
	foreach (Finder::findFiles('*.js', '*.css', '*.phtml')->from("$dir53/Nette") as $file) {
		$project->minifyJs($file);
	}
	foreach (Finder::findFiles('*.js', '*.css', '*.phtml')->from("$dir52p/Nette") as $file) {
		$project->minifyJs($file);
	}
	foreach (Finder::findFiles('*.js', '*.css', '*.phtml')->from("$dir52n/Nette") as $file) {
		$project->minifyJs($file);
	}


	// build minified version
	$project->minify("$dir53/Nette", "$dir53/Nette-minified", TRUE);
	$project->minify("$dir52p/Nette", "$dir52p/Nette-minified", FALSE);
	$project->minify("$dir52n/Nette", "$dir52n/Nette-minified", FALSE);


	if ($branch !== 'v0.9.x') { // copy Nette to submodules
		$project->copy("$dir53/Nette-minified/loader.php", "$dir53/examples/libs/Nette/Nette/loader.php");
		$project->copy("$dir52p/Nette-minified/loader.php", "$dir52p/examples/libs/Nette/Nette/loader.php");
		$project->copy("$dir52n/Nette-minified/loader.php", "$dir52n/examples/libs/Nette/Nette/loader.php");
		$project->copy("$dir53/Nette-minified/loader.php", "$dir53/sandbox/libs/Nette/Nette/loader.php");
		$project->copy("$dir52p/Nette-minified/loader.php", "$dir52p/sandbox/libs/Nette/Nette/loader.php");
		$project->copy("$dir52n/Nette-minified/loader.php", "$dir52n/sandbox/libs/Nette/Nette/loader.php");
	}


	// build API doc
	$project->apiGen("$dir53/Nette", "$dir53/API-reference");
	$project->apiGen("$dir52p/Nette", "$dir52p/API-reference");
	$project->apiGen("$dir52n/Nette", "$dir52n/API-reference");


	// lint PHP files
	foreach (Finder::findFiles('*.php', '*.phpt')->from($dir53) as $file) {
		$project->phpLint($file);
	}
	foreach (Finder::findFiles('*.php', '*.phpt')->from($dir52p) as $file) {
		$project->phpLint($file, $project->php52Executable);
	}
	foreach (Finder::findFiles('*.php', '*.phpt')->from($dir52n) as $file) {
		$project->phpLint($file, $project->php52Executable);
	}


	// create archives
	$project->zip("$distDir/archive/NetteFramework-{$label}-(".date('Y-m-d').").7z", array($dir53, $dir52p, $dir52n));
	$project->zip("$distDir/$dir53.zip", $dir53);
	$project->zip("$distDir/$dir52p.zip", $dir52p);
	$project->zip("$distDir/$dir52n.zip", $dir52n);
};