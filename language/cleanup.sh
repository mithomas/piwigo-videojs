find . -type f -name "description.txt" -exec rm {} \;
find . -type d -name ".svn" -exec rm -rf {} \;
