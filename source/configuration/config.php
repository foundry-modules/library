<?php
include(%BOOTCODE%_FOUNDRY_PATH . '/scripts/bootloader' . $this->extension);
?>
<?php echo %BOOTCODE%_FOUNDRY_BOOTCODE; ?>.setup(<?php echo $this->toJSON(); ?>);