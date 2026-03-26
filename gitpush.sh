#!/bin/bash
# ============================================
# TSG DMS — Git Push Script
# Usage: ./gitpush.sh "your commit message"
# ============================================

# Go to project folder
cd C:/Projects/tsg-dms || { echo "ERROR: Project folder not found!"; exit 1; }

# Check if commit message provided
if [ -z "$1" ]; then
    echo "Enter commit message:"
    read MSG
else
    MSG="$1"
fi

echo ""
echo "=== Checking status ==="
git status

echo ""
echo "=== Staging all changes ==="
git add .

echo ""
echo "=== Committing: $MSG ==="
git commit -m "$MSG"

echo ""
echo "=== Pushing to GitHub ==="
git push

echo ""
echo "=== Done! ==="
git log --oneline -3
