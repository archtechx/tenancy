#include "sqlite3ext.h"
SQLITE_EXTENSION_INIT1

static int deny_attach_authorizer(void *user_data, int action_code, const char *param1, const char *param2, const char *dbname, const char *trigger) {
    return action_code == SQLITE_ATTACH // 24
        ? SQLITE_DENY // 1
        : SQLITE_OK; // 0
}

#ifdef _WIN32
__declspec(dllexport)
#endif
int sqlite3_noattach_init(sqlite3 *db, char **pzErrMsg, const sqlite3_api_routines *pApi) {
    SQLITE_EXTENSION_INIT2(pApi);

    if (sqlite3_set_authorizer(db, deny_attach_authorizer, 0) != SQLITE_OK) {
        *pzErrMsg = sqlite3_mprintf("Tenancy: Failed to set authorizer");
        return SQLITE_ERROR;
    } else {
        return SQLITE_OK;
    }
}
