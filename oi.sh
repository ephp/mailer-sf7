#!/bin/bash

# Versione di default da utilizzare
DEFAULT_VERSION="v7.0"

# Inizializzazione dei flag
ALL=false
COMMIT_MESSAGE=""
BUNDLES=()
ASK_FOR_BRANCH=true  # Flag per chiedere all'utente quale branch usare
NEW_BRANCH=""        # Nuovo flag per specificare un branch da creare

# Funzione per ottenere dinamicamente la lista dei bundle
get_available_bundles() {
    local bundles_dir="vendor/oi"
    local available_bundles=()

    # Controlla se la directory esiste
    if [ ! -d "$bundles_dir" ]; then
        echo "ERRORE: La directory $bundles_dir non esiste" >&2
        return 1
    fi

    # Legge tutte le directory in vendor/oi
    for dir in "$bundles_dir"/*; do
        if [ -d "$dir" ]; then
            bundle_name=$(basename "$dir")
            # Esclude directory nascoste o di sistema
            if [[ ! "$bundle_name" =~ ^\. ]]; then
                available_bundles+=("$bundle_name")
            fi
        fi
    done

    # Ordina alfabeticamente
    IFS=$'\n' available_bundles=($(sort <<<"${available_bundles[*]}"))
    unset IFS

    echo "${available_bundles[@]}"
}

# Funzione per mostrare la lista dei bundle disponibili
show_bundles() {
    echo "Bundle disponibili:"
    local bundles=($(get_available_bundles))

    if [ ${#bundles[@]} -eq 0 ]; then
        echo "  Nessun bundle trovato in vendor/oi"
        return 1
    fi

    for bundle in "${bundles[@]}"; do
        echo "  --$bundle"
    done

    echo ""
    echo "Esempi d'uso:"
    echo "  $0 --${bundles[0]} \"Messaggio di commit\""
    echo "  $0 --all \"Update generale di tutti i bundle\""
    exit 0
}

# Funzione per controllare e utilizzare il branch più recente
check_and_use_latest_branch() {
    local repo=$1
    local default_branch=""
    local found_branches=()

    # Recupera tutti i branch remoti
    git fetch --all > /dev/null 2>&1

    # Lista tutti i branch remoti
    local branches=$(git branch -r | grep "origin/" | sed 's/origin\///' | sed 's/^[[:space:]]*//')

    # Cerca branch v7.x in ordine decrescente (v7.9, v7.8, ..., v7.0)
    for ver in {9..0}; do
        if echo "$branches" | grep -q "v7.$ver"; then
            found_branches+=("v7.$ver")
        fi
    done

    # Cerca branch personalizzati (test_*)
    local custom_branches=($(echo "$branches" | grep "test_" | head -n 3))

    # Se NEW_BRANCH è specificato, crea e usa quel branch
    if [ -n "$NEW_BRANCH" ]; then
        default_branch="$NEW_BRANCH"
        echo "Creazione nuovo branch $default_branch per $repo..."

        # Verifica se il branch esiste già remotamente
        if echo "$branches" | grep -q "^$default_branch$"; then
            echo "ATTENZIONE: Il branch $default_branch esiste già remotamente."
            echo "Utilizzerò il branch esistente invece di crearne uno nuovo."
        else
            # Trova il branch più recente come base
            local base_branch=""
            if [ ${#found_branches[@]} -gt 0 ]; then
                base_branch=${found_branches[0]}
            elif [ ${#custom_branches[@]} -gt 0 ]; then
                base_branch=${custom_branches[0]}
            else
                base_branch="master"
            fi

            echo "Utilizzo $base_branch come base per il nuovo branch $default_branch"
            git checkout $base_branch > /dev/null 2>&1
            git pull origin $base_branch > /dev/null 2>&1
            git checkout -b $default_branch > /dev/null 2>&1
        fi

        return 0
    fi

    # Se non abbiamo trovato né branch v7.x né personalizzati
    if [ ${#found_branches[@]} -eq 0 ] && [ ${#custom_branches[@]} -eq 0 ]; then
        echo "ERRORE: Nessun branch v7.x o test_* trovato per $repo."
        echo "Lo script gestisce solo questi tipi di branch."
        return 1
    fi

    # Se c'è un solo branch disponibile (tra v7.x e personalizzati), salta la domanda
    local total_branches=$((${#found_branches[@]} + ${#custom_branches[@]}))
    if [ $total_branches -eq 1 ]; then
        # Usa l'unico branch disponibile
        if [ ${#found_branches[@]} -eq 1 ]; then
            default_branch=${found_branches[0]}
        else
            default_branch=${custom_branches[0]}
        fi
        echo "Un solo branch disponibile: $default_branch. Verrà usato automaticamente."
    # Se abbiamo trovato branch v7.x o personalizzati e ASK_FOR_BRANCH è true
    elif [ "$ASK_FOR_BRANCH" = true ]; then
        echo "Branch disponibili per $repo:"
        local branch_index=1

        # Mostra branch v7.x
        for branch in "${found_branches[@]}"; do
            echo "  $branch_index) $branch"
            ((branch_index++))
        done

        # Mostra branch personalizzati
        for branch in "${custom_branches[@]}"; do
            echo "  $branch_index) $branch (personalizzato)"
            ((branch_index++))
        done

        echo "Quale branch vuoi usare? (1-$((branch_index-1)), default: 1)"
        read choice

        # Se nessuna scelta, usa il primo branch
        if [ -z "$choice" ]; then
            choice=1
        fi

        # Converti la scelta in numero
        choice=$(($choice))

        # Seleziona il branch in base alla scelta
        if [ $choice -le ${#found_branches[@]} ] && [ $choice -gt 0 ]; then
            default_branch=${found_branches[$((choice-1))]}
        elif [ $choice -le $((${#found_branches[@]} + ${#custom_branches[@]})) ]; then
            default_branch=${custom_branches[$((choice-${#found_branches[@]}-1))]}
        else
            # Scelta non valida, usa il primo branch disponibile
            echo "Scelta non valida, utilizzo il primo branch disponibile."
            if [ ${#found_branches[@]} -gt 0 ]; then
                default_branch=${found_branches[0]}
            else
                default_branch=${custom_branches[0]}
            fi
        fi
    else
        # Se ASK_FOR_BRANCH è false, usa il branch v7.x più recente o un custom se non ci sono v7.x
        if [ ${#found_branches[@]} -gt 0 ]; then
            default_branch=${found_branches[0]}
        else
            default_branch=${custom_branches[0]}
        fi
    fi

    echo "Utilizzo branch $default_branch per $repo."

    # Controlla se il branch locale esiste
    if ! git show-ref --verify --quiet refs/heads/$default_branch; then
        echo "Creazione branch locale $default_branch..."
        git checkout -b $default_branch origin/$default_branch > /dev/null 2>&1 || \
        git checkout -b $default_branch > /dev/null 2>&1
    else
        echo "Passaggio a branch locale $default_branch..."
        git checkout $default_branch > /dev/null 2>&1
    fi

    echo "Pull delle ultime modifiche dal repository remoto..."
    git pull origin $default_branch 2>/dev/null || echo "Nessun remote branch trovato per $default_branch, potrebbe essere un nuovo branch."

    return 0
}

# Funzione per aggiornare un repository
update_repo() {
    local repo=$1
    local dir_name=$2

    # Se il nome della directory è vuoto, usa il nome del repo
    if [ -z "$dir_name" ]; then
        dir_name=$repo
    fi

    echo "***********************"
    echo "* ${repo}Bundle *"
    echo "***********************"

    cd $dir_name || { echo "La directory $dir_name non esiste"; return 1; }

    if ! check_and_use_latest_branch $repo; then
        echo "Impossibile trovare un branch compatibile per $repo. Operazione saltata."
        cd ..
        return 1
    fi

    local branch=$(git rev-parse --abbrev-ref HEAD)

    git add .
    git status

    if [ -n "$COMMIT_MESSAGE" ]; then
        git commit -a -m "$COMMIT_MESSAGE" || echo "Nessuna modifica da committare per $repo"
        git push origin $branch
        git branch --set-upstream-to=origin/$branch $branch 2>/dev/null
    else
        echo "Nessun messaggio di commit specificato, operazione di commit saltata."
    fi

    cd ..
    return 0
}

# Funzione di aiuto
show_help() {
    echo "Utilizzo: $0 [opzioni] \"messaggio di commit\""
    echo ""
    echo "Opzioni:"
    echo "  --all                     Aggiorna tutti i bundle"
    echo "  --bundle-name             Aggiorna solo il bundle specificato"
    echo "                            (usa --bundles per vedere la lista completa)"
    echo "  --bundles                 Mostra tutti i bundle disponibili"
    echo "  --auto                    Non chiede quale branch usare, seleziona automaticamente"
    echo "                            il più recente v7.x o custom branch"
    echo "  --branch=BRANCH_NAME      Crea e utilizza un nuovo branch con il nome specificato"
    echo "  --help                    Mostra questo messaggio di aiuto"
    echo ""
    echo "Esempio: $0 --api --blog \"Fix bug nella gestione API\""
    echo "         $0 --all \"Update generale di tutti i bundle\""
    echo "         $0 --auto --all \"Update generale senza richieste interattive\""
    echo "         $0 --branch=v7.1 --api \"Creazione branch v7.1 con update API\""
    echo "         $0 --bundles  # Mostra tutti i bundle disponibili"
    exit 0
}

# Parsing degli argomenti
while [[ $# -gt 0 ]]; do
    case $1 in
        --all)
            ALL=true
            shift
            ;;
        --auto)
            ASK_FOR_BRANCH=false
            shift
            ;;
        --branch=*)
            NEW_BRANCH="${1#*=}"
            shift
            ;;
        --bundles)
            show_bundles
            ;;
        --help)
            show_help
            ;;
        --*)
            # Rimuove il prefisso -- e converte in minuscolo
            bundle_name=${1#--}
            bundle_name=$(echo $bundle_name | tr '[:upper:]' '[:lower:]')
            BUNDLES+=("$bundle_name")
            shift
            ;;
        *)
            if [ -z "$COMMIT_MESSAGE" ]; then
                COMMIT_MESSAGE="$1"
            fi
            shift
            ;;
    esac
done

# Verifica che sia stato fornito un messaggio di commit
if [ -z "$COMMIT_MESSAGE" ] && [ "$ALL" = true -o ${#BUNDLES[@]} -gt 0 ]; then
    echo "ERRORE: È necessario specificare un messaggio di commit."
    echo "Esempio: $0 --api \"Fix bug nella gestione API\""
    exit 1
fi

# Ottieni dinamicamente la lista dei bundle disponibili
ALL_BUNDLES=($(get_available_bundles))

# Verifica che ci siano bundle disponibili
if [ ${#ALL_BUNDLES[@]} -eq 0 ]; then
    echo "ERRORE: Nessun bundle trovato in vendor/oi"
    exit 1
fi

# Se non sono specificati bundle, considera --all
if [ "$ALL" = false ] && [ ${#BUNDLES[@]} -eq 0 ]; then
    echo "Nessun bundle specificato, mostra l'help:"
    show_help
fi

# Entra nella directory principale
cd vendor/oi || { echo "ERRORE: La directory vendor/oi non esiste"; exit 1; }

# Funzione per controllare se un bundle è stato selezionato
is_bundle_selected() {
    local bundle=$1

    if [ "$ALL" = true ]; then
        return 0
    fi

    for selected in "${BUNDLES[@]}"; do
        if [ "$selected" = "$bundle" ]; then
            return 0
        fi
    done

    return 1
}

# Verifica che i bundle specificati esistano effettivamente
for selected_bundle in "${BUNDLES[@]}"; do
    bundle_exists=false
    for available_bundle in "${ALL_BUNDLES[@]}"; do
        if [ "$selected_bundle" = "$available_bundle" ]; then
            bundle_exists=true
            break
        fi
    done

    if [ "$bundle_exists" = false ]; then
        echo "ERRORE: Il bundle '$selected_bundle' non esiste."
        echo "Usa --bundles per vedere la lista dei bundle disponibili."
        exit 1
    fi
done

# Elabora ogni bundle
for bundle in "${ALL_BUNDLES[@]}"; do
    # Se il bundle è selezionato, aggiornalo
    if is_bundle_selected "$bundle"; then
        update_repo "$bundle" "$bundle"
    fi
done

cd ../..

echo "Operazioni completate!"
exit 0