all: build

include ../../build/modules.mk

build:
	mkdir -p ${FOUNDRY}/joomla
	cat source/framework.php | ${RESOLVE_NAMESPACE} > ${FOUNDRY}/joomla/framework.php
	cat source/configuration.php | ${RESOLVE_NAMESPACE} > ${FOUNDRY}/joomla/configuration.php
	cat source/compiler.php | ${RESOLVE_NAMESPACE} > ${FOUNDRY}/joomla/compiler.php
	cat source/module.php | ${RESOLVE_NAMESPACE} > ${FOUNDRY}/joomla/module.php

	mkdir -p ${FOUNDRY}/joomla/configuration
	cat source/configuration/config.php | ${RESOLVE_NAMESPACE} > ${FOUNDRY}/joomla/configuration/config.php

	mkdir -p ${FOUNDRY}/joomla/compiler
	cat source/compiler/optimized.php | ${RESOLVE_NAMESPACE} > ${FOUNDRY}/joomla/compiler/optimized.php
	cat source/compiler/resources_manifest.php | ${RESOLVE_NAMESPACE} > ${FOUNDRY}/joomla/compiler/resources_manifest.php
	cat source/compiler/resources.php | ${RESOLVE_NAMESPACE} > ${FOUNDRY}/joomla/compiler/resources.php
	cat source/compiler/static.php | ${RESOLVE_NAMESPACE} > ${FOUNDRY}/joomla/compiler/static.php



