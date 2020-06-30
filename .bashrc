goto () {
    output=`php -f ~/"Projects/goto.php" $1 $2`
    cd ~/"Projects/$output"
}
