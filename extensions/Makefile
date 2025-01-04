.PHONY: all headers

OUTPUT :=

CCFLAGS += -shared -Os

ifeq ($(OS),Windows_NT)
	OUTPUT = lib/noattach.dll
	CC = clang
else
	UNAME := $(shell uname)
	CCFLAGS += -fPIC
	ARCH := $(if $(ARCH),$(ARCH),$(shell uname -m))
	ifeq ($(UNAME),Darwin)
		ifeq ($(ARCH),arm64)
			OUTPUT = lib/arm/noattach.dylib
		else
			OUTPUT = lib/noattach.dylib
		endif
	else
		ifeq ($(ARCH),aarch64)
			OUTPUT = lib/arm/noattach.so
		else
			OUTPUT = lib/noattach.so
		endif
	endif
endif

$(info OUTPUT=$(OUTPUT))

all: $(OUTPUT)

headers:
	# To simplify compilation across platforms, we include sqlite3ext.h in this directory.
	curl -L https://www.sqlite.org/2024/sqlite-amalgamation-3470200.zip -o sqlite-src.zip
	unzip sqlite-src.zip
	cp sqlite-amalgamation-3470200/*.h .

$(OUTPUT): noattach.c
	# We don't link against libsqlite3 since PHP statically links its own libsqlite3.
	$(CC) $(CCFLAGS) -o $@ $<
