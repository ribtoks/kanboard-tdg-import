# kanboard-tdg-import

Kanboard plugin to import TODO tasks created by [tdg](https://github.com/ribtoks/tdg)

[![Codacy Badge](https://api.codacy.com/project/badge/Grade/74b89c2442e9474aac12362a2d37cd79)](https://www.codacy.com/app/ribtoks/kanboard-tdg-import)
[![Maintainability](https://api.codeclimate.com/v1/badges/92fc61f61afcfddf4cde/maintainability)](https://codeclimate.com/github/ribtoks/kanboard-tdg-import/maintainability)

## About

This plugin allows to synchronize tasks based on current TODO/FIXME/BUG comments in the project source code to tasks in [kanboard](https://github.com/kanboard/kanboard). Comments are extracted using [tdg utility](https://github.com/ribtoks/tdg).

In order to get tasks synchronized, you have to create a project in kanboard with name equal to the name of your project. After TODO comment is removed from source code, it is automatically moved to the last column (usually, "Done") in the kanboard project.

## Setup

-   Install [kanboard](https://github.com/kanboard/kanboard) (recommended options: docker or raspberry pi)
-   Download this plugin from [Releases](https://github.com/ribtoks/kanboard-tdg-import/releases/latest) and extract to `kanboard-root/plugins/` directory
-   Create a kanboard project with the name equal to the name of the project you want to track with standard layout ("TODO", "In progress" and "DONE")
-   From admin user go to global Settings and in the API section copy token and endpoint url.
-   Install [tdg](https://github.com/ribtoks/tdg) using `go get github.com/ribtoks/tdg`
-   Create git post-commit hook that will fetch current comments from source code and send them to kanboard

Sample script (replace API token and endpoint with yours):

    #!/bin/bash

    API_TOKEN='your-api-token-here'
    API_ENDPOINT='http://192.168.1.100/kanboard/jsonrpc.php'

    JSON_OUT=`tdg -root /path/to/project/root -include "\.cpp$"`

    PAYLOAD="{\"jsonrpc\": \"2.0\", \"id\": 123456789, \"method\": \"importTodoComments\", \"params\": ${JSON_OUT}}"

    curl \
        -u "jsonrpc:${API_TOKEN}" \
        -d "${PAYLOAD}" \
        "${API_ENDPOINT}"

Now when you run `git commit`, all your comments will be automatically synchronized.
