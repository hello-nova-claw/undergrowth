# Changelog fragments

Each pull request adds its changelog line here as `<issue>-<slug>.txt`, named like its branch, for example `12-vector-table.txt`. Write one line per entry, without the leading `* `, in the same plain style as the lines under `= Unreleased =` in `readme.txt`.

Don't edit the changelog in `readme.txt` directly. At release time `npm run changelog` adds every fragment under `= Unreleased =`, in issue number order, and deletes the fragments.
