include ../../build/modules.mk

MODULE = bootstrap
FILENAME = ${MODULE}.php

all:
	cat ${SOURCE} | ${RESOLVE_NAMESPACE} > ${FOUNDRY}/joomla/${FILENAME}
