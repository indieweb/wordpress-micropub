#!/bin/bash
#
# Commits and pushes to the wordpress.org plugin directory repo.
cp -f micropub.php readme.txt ../wordpress.org-micropub/trunk/
cd ../wordpress.org-micropub/trunk/
svn ci
cd -
