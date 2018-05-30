#!/bin/bash

# Collected from web, using to copy data from one redis cluster to another.

src_ip=127.0.0.1
src_port=6370
src_auth='xxx'
src_db=0
dest_ip=127.0.0.1
dest_port=6085
dest_auth='xxx'
dest_db=0

i=1
redis-cli -h $src_ip -p $src_port -a $src_auth keys "*" | while read key
do
    redis-cli -h $dest_ip -p $dest_port -a $dest_auth -n $dest_db del $key
    redis-cli -h $src_ip -p $src_port -a $src_auth -n $src_db --raw dump $key | perl -pe 'chomp if eof' | redis-cli -h $dest_ip -p $dest_port -a $dest_auth -n $dest_db -x restore $key 0
    echo "$i migrate key $key"
    ((i++))
done
