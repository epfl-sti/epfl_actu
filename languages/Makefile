# Makefile to automate tasks pertaining to translations.

.PHONY: all

all: compile epfl-persons.pot

.PHONY: compile
compile: $(patsubst %.po, %.mo, $(wildcard *.po))

%.mo: %.po
	msgfmt -o $@ $<

.PHONY: epfl-persons.pot
epfl-persons.pot:
	find .. -name "*.php" -or -name "*.inc" \
	| xargs \
	  xgettext -L php --from-code=utf-8 \
	  -d "epfl-persons" -k"__x" -k"___" -k"__e" -k"__id" \
	  --package-name=epfl-persons \
	  --msgid-bugs-address=stiitweb@groupes.epfl.ch \
	  -o epfl-persons.pot

.PHONY: msgmerge
msgmerge:
	@test -n "$(TARGET)" || { echo >&2 "Usage: make msgmerge TARGET=en_JP.po"; exit 1; }
	msgmerge -U $(TARGET) epfl-persons.pot
