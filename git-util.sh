function get_project_name {
    $(shell basename $(shell git config --get remote.origin.url) | sed 's/\.git//')
}

function get_branch_name {
    $(shell git symbolic-ref --short HEAD 2>/dev/null)
}

function get_latest_version {
    $(shell git log -1 --pretty=format:"%H")
}
