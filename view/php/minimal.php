<!DOCTYPE html >
<html>
<head>
<!-- this is validation before display data	 -->
  <title><?php if(!empty($page['title'])) echo $page['title'] ?></title>
  <script>var baseurl="<?php echo Friendica\Core\System::baseUrl() ?>";</script>
  <?php if(!empty($page['htmlhead'])) echo $page['htmlhead'] ?>
</head>
<body class="minimal">
	<section><?php if(!empty($page['content'])) echo $page['content']; ?>
		<div id="page-footer"></div>
	</section>
</body>
</html>
