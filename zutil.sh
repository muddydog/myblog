#!/bin/bash

if [ $# -lt 1 -o "$1" = "help" ]; then
    echo -ne "Usage: sh $0 [option]\n"
    echo -ne "Option:\n"
    echo -ne "  help\t\tshow this usage help\n"
    echo -ne "  redis\t\tshow redis servers and connect\n"
    echo -ne "  mysql\t\tshow mysql servers and connect\n"
    echo -ne "  mongo\t\tshow mongo servers and connect\n"
    echo -ne "  es\t\tshow info of es servers\n"
    exit
fi
case $1 in
    redis)
    title="index\tproduct\thost\tport\tauth"
    arr=(
    "local 127.0.0.1 6379 test" 
    )
    ;; 
    mysql)
    title="index\tproduct\thost\tport\tdb\tuser\tpassword"
    arr=(
    "local 127.0.0.1 3306 mysql root root" 
    )
    ;;
    mongo)
    title="index\tproduct\thost\tport\tuser\tauth"
    arr=(
    "test 127.0.0.1 7186 mongo 132343566"
    )
    ;;
    es)
    title="index\tES\tKibana\tES_user\tES_auth\tK_user"
    arr=(
    "test http://127.0.0.1:9200 http://127.0.0.1:9230 test test_auth test_r"
    )
    ;;
    *)
    arr=()
    title="nothing"
    ;;
esac

#while [ $i -lt ${#arr[@]} ]
#do
#    echo -e "[$i] ${arr[$it]}\n"
#done

len=${#arr[@]}
echo -e "\n==== $1 ===="
echo -en "$title\n"
for((i=0;$i<$len;i++))
do
    echo -en "[$i]\t${arr[$i]// /\t}\n"
done

op=-1
until [ $op -ge 0 -a $op -lt $len ]
do
    echo -en "please input an index to continue:"
    read op
done

conf=(${arr[$op]})
case $1 in
    redis)
    echo -e "\n--- connecting redis for ${conf[0]} ---"
    redis-cli -h ${conf[1]} -p ${conf[2]} -a ${conf[3]} 
    ;;
    mysql)
    echo -e "\n--- connecting mysql for ${conf[0]} ---"
    mysql -h ${conf[1]} -P ${conf[2]} -D ${conf[3]} -u ${conf[4]} -p${conf[5]}
    ;;
    mongo)
    echo -e "\n--- connecting mongo for ${conf[0]} ---"
    mongo ${conf[1]}:${conf[2]}/admin -u ${conf[3]} -p${conf[4]}
    ;;
    es)
    echo -e "\n--- show es info for ${conf[0]} ---"
    curl -u ${conf[3]}:${conf[4]} ${conf[1]}
    ;;
    *)
    ;;
esac
