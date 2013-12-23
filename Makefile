all: clean folders modules build libs

include ../../build/modules.mk

TARGET = ${FOUNDRY}/joomla

%:
	if [ -e "source/$*.php" ]; \
	then make file name=$* | ${RESOLVE_NAMESPACE} > ${TARGET}/$*.php; fi

file:
	@@cat source/header.php
	@@cat source/${name}.php | sed '/\<\?php/{x;/Y/!{s/^/Y/;h;d;};x;}'

folders:
	mkdir -p ${TARGET}
	mkdir -p ${TARGET}/compiler
	mkdir -p ${TARGET}/configuration
	mkdir -p ${TARGET}/libraries
	mkdir -p ${TARGET}/stylesheet

modules:
	make -C "modules/lessphp"
	make -C "modules/cssmin"

build:

	make \
		framework \
		compiler \
		module \
		configuration \
		configuration/config \
		compiler/optimized \
		compiler/resources_manifest \
		compiler/resources \
		compiler/static \
		stylesheet \
		stylesheet/analyzer \
		stylesheet/builder \
		stylesheet/compiler \
		stylesheet/compressor \
		stylesheet/task \
		libraries/closure \
		libraries/cssmin \
		libraries/jsmin \
		libraries/less

libs:
	rm -fr ${FOUNDRY}/libraries
	mv ${TARGET}/libraries ${FOUNDRY}/libraries

clean:
	rm -fr ${TARGET}

.PHONY: all modules