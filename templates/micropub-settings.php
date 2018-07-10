<div class="wrap">
<h1>Micropub Options</h1>
<form method="post" action="options.php">
<?php
	//add_settings_section callback is displayed here. For every new section we need to call settings_fields.
	settings_fields("micropub_writing");
	// all the add_settings_field callbacks is displayed here
	do_settings_sections("micropub");
	
	submit_button();
?>
</form>
</div>
<?php settings_fields( 'micropub_writing' ); ?>
