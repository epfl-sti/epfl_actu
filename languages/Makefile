# Makefile to automate tasks pertaining to translations.

.PHONY: all

all: compile epfl-ws.pot

.PHONY: compile
compile: $(patsubst %.po, %.mo, $(wildcard *.po))

%.mo: %.po
	msgfmt -o $@ $<

.PHONY: epfl-ws.pot
epfl-ws.pot:
	find .. -name "*.php" -or -name "*.inc" \
	| xargs \
	  xgettext -L php --from-code=utf-8 \
	  -d "epfl-ws" -k"__x" -k"___" -k"__e" -k"__id" \
	  --package-name=epfl-ws \
	  --msgid-bugs-address=stiitweb@groupes.epfl.ch \
	  -o epfl-ws.pot

.PHONY: msgmerge
msgmerge:
	@test -n "$(TARGET)" || { echo >&2 "Usage: make msgmerge TARGET=en_JP.po"; exit 1; }
	msgmerge -U $(TARGET) epfl-ws.pot
