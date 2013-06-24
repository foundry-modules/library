all: build

include ../../build/modules.mk

build:
	cat source/configuration.php | ${RESOLVE_NAMESPACE} > ${FOUNDRY}/joomla/configuration.php
	cat source/compiler.php | ${RESOLVE_NAMESPACE} > ${FOUNDRY}/joomla/compiler.php
	cat source/constants.php | ${RESOLVE_NAMESPACE} > ${FOUNDRY}/joomla/constants.php
	cat source/module.php | ${RESOLVE_NAMESPACE} > ${FOUNDRY}/joomla/module.php
	mkdir -p ${FOUNDRY}/joomla/compiler
	cp source/compiler/* ${FOUNDRY}/joomla/compiler
