#!/bin/bash
#
# Commits and pushes to the wordpress.org plugin directory repo.
#
# To tag git for the current version:
#
# git tag -a vX.Y --cleanup=verbatim
#
# ...delete git-generated comments at bottom, leave first line in editor blank
# to omit release "title" in github, '### Notable changes' second line, then
# copy changelog.
git push --tags

cp -f micropub.php readme.txt ../wordpress.org-micropub/trunk/
cd ../wordpress.org-micropub/trunk/
svn ci
cd -
