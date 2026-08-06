<?php

declare(strict_types=1);

/**
 * French copy for the User plugin's screens and its verification email.
 *
 * A note on "Locale" vs "Language": English uses both, and they are NOT
 * synonyms here — "Language" is the display language, "Locale" is the full
 * formatting context (dates, numbers, currency). French keeps the distinction
 * as « Langue » and « Paramètres régionaux »; collapsing them into one word
 * would leave two settings with the same label and no way to tell which does
 * what.
 */
return [
    'common' => [
        'loading'      => 'Chargement…',
        'reload'       => 'Actualiser',
        'cancel'       => 'Annuler',
        'create'       => 'Créer',
        'edit'         => 'Modifier',
        'delete'       => 'Supprimer',
        'view'         => 'Voir',
        'status'       => 'Statut',
        'email'        => 'E-mail',
        'username'     => 'Nom d\'utilisateur',
        'password'     => 'Mot de passe',
        'created'      => 'Créé le',
        'save_changes' => 'Enregistrer les modifications',
        'back_to_list' => 'Retour à la liste',
        'loaded_from'  => 'Chargé depuis',
        'submits_to'   => 'Envoyé à',
    ],

    'nav' => [
        'brand'     => 'Gestion des utilisateurs',
        'all_users' => 'Tous les utilisateurs',
        'create'    => 'Créer',
        'settings'  => 'Paramètres',
    ],

    'users' => [
        'title'          => 'Utilisateurs',
        'empty'          => 'Aucun utilisateur pour le moment.',
        'create_user'    => 'Créer un utilisateur',
        'create_account' => 'Créer un compte',
        'detail'         => 'Détail de l\'utilisateur',
        'edit_user'      => 'Modifier l\'utilisateur',
        'new_password'   => 'Nouveau mot de passe',
        // Comme en anglais : préciser que le champ vide CONSERVE le mot de
        // passe. L'interprétation inverse verrouillerait le compte.
        'partial_update' => 'Mise à jour partielle — laissez le mot de passe vide pour le conserver.',
    ],

    'verify' => [
        'title'         => 'Vérifiez votre adresse e-mail',
        'token'         => 'Jeton de vérification',
        'submit'        => 'Vérifier l\'e-mail',
        'email_heading' => 'Confirmez votre adresse e-mail',
    ],

    'settings' => [
        'profile'            => 'Profil',
        'first_name'         => 'Prénom',
        'last_name'          => 'Nom',
        'phone'              => 'Téléphone',
        'avatar_url'         => 'URL de l\'avatar (http/https)',
        'save_profile'       => 'Enregistrer le profil',
        'preferences'        => 'Préférences',
        'locale'             => 'Paramètres régionaux',
        'language'           => 'Langue',
        'timezone'           => 'Fuseau horaire',
        'currency'           => 'Devise',
        'theme'              => 'Thème',
        'save_preferences'   => 'Enregistrer les préférences',
        'notifications'      => 'Notifications',
        'save_notifications' => 'Enregistrer les notifications',
        'privacy'            => 'Confidentialité',
        'profile_visibility' => 'Visibilité du profil',
        'save_privacy'       => 'Enregistrer la confidentialité',
    ],

    'feedback' => [
        'title'       => 'Envoyer un commentaire',
        'category'    => 'Catégorie',
        'message'     => 'Message',
        'rating'      => 'Note',
        'rating_hint' => 'Note (1–5, facultatif)',
        'send'        => 'Envoyer le commentaire',
        'empty'       => 'Aucun commentaire.',
        'user'        => 'Utilisateur',
        'triage'      => 'Traitement',
    ],

    'placeholder' => [
        'title'   => 'Plugin utilisateur',
        'heading' => 'Utilisateur',
        'edit_at' => 'Modifiez cette vue dans',
    ],
];
