all: build

include ../../build/modules.mk

build:
	cat source/configuration.php | ${RESOLVE_NAMESPACE} > ${FOUNDRY}/joomla/configuration.php
