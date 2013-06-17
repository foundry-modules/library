all: build

include ../../build/modules.mk

MODULE = bootstrap
FILENAME = ${MODULE}.php
SOURCE = ${SOURCE_SCRIPT_FOLDER}/${FILENAME}

build:
	cat ${SOURCE} | ${RESOLVE_NAMESPACE} > ${FOUNDRY}/joomla/${FILENAME}
