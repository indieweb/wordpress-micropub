<div class="wrap">
<h1>Micropub Options</h1>
<form method="post" action="options.php">
<?php
	settings_fields("micropub");
	// all the add_settings_field callbacks is displayed here
	do_settings_sections("micropub");
	
	submit_button();
?>
</form>
</div>
<?php settings_fields( 'micropub_writing' ); ?>
