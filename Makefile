include ../../build/modules.mk

MODULE = bootstrap
FILENAME = ${MODULE}.php
SOURCE = ${SOURCE_DIR}/${FILENAME}

all:
	cat ${SOURCE} | ${RESOLVE_NAMESPACE} > ${FOUNDRY}/joomla/${FILENAME}
