# UCP WellKnown Module

Module PrestaShop pour l'intégration du protocole UCP (Universal Checkout Protocol) avec gestion complète des sessions de paiement.

## Vue d'ensemble

Le module UCP WellKnown permet de créer et gérer des sessions de checkout sécurisées avec validation des produits, gestion des clients, application de codes promo et respect complet du protocole UCP.

## Fonctionnalités principales

### 🛒 Gestion des sessions de paiement
- Création de sessions avec identifiant unique
- Mise à jour atomique des produits
- Calcul automatique des totaux
- Gestion des codes promotionnels

### 👥 Gestion des clients
- Création automatique de comptes clients
- Réutilisation des clients existants
- Validation des informations obligatoires
- Gestion des adresses complètes

### 🎦 Gestion des produits
- Validation en temps réel du stock
- Support des produits personnalisables
- Ajout, modification et suppression
- Calcul des prix TTC et HT

### 🎫 Codes promotionnels
- Application et suppression de codes promo
- Validation des limites d'utilisation
- Calcul automatique des réductions
- Support des réductions en pourcentage et montant

## Architecture

### Classes principales
- `UcpHeaderValidator` - Validation des en-têtes UCP
- `UcpCheckoutSessionValidator` - Validation des payloads
- `UcpCartManager` - Gestion des paniers
- `UcpBuyerManager` - Gestion des clients
- `UcpCheckoutSessionUpdater` - Mise à jour des sessions

### Controllers
- `checkout_sessions` - Endpoint principal pour les sessions
- Support des méthodes GET, POST, PUT
- Gestion des erreurs structurée
- Logs de sécurité intégrés

## Endpoints API

### Créer une session de paiement
**Méthode**: POST  
**URL**: `/prestashop/module/ucpwellknown/checkout_sessions`

**En-têtes requis**:
- `UCP-Agent`: Identifiant du client
- `request-id`: UUID unique
- `idempotency-key`: Clé d'idempotence
- `request-signature`: Signature de la requête

**Payload**:
```json
{
  "line_items": [
    {
      "product_id": 1,
      "quantity": 2,
      "customization_data": {
        "fields": [
          {
            "type": 0,
            "value": "Texte personnalisé",
            "required": 0
          }
        ]
      }
    }
  ],
  "buyer": {
    "email": "client@example.com",
    "first_name": "Jean",
    "last_name": "Dupont",
    "address": "123 rue de la Paix",
    "city": "Paris",
    "postal_code": "75001",
    "country": "France",
    "phone": "+33612345678",
    "company": "Société ABC"
  }
}
```

### Récupérer une session
**Méthode**: GET  
**URL**: `/prestashop/module/ucpwellknown/checkout_sessions?checkout_session_id={id}`

Retourne les détails complets de la session avec produits, totaux et codes promo appliqués.

### Mettre à jour une session
**Méthode**: POST (traité comme PUT)  
**URL**: `/prestashop/module/ucpwellknown/checkout_sessions?checkout_session_id={id}`

Permet de modifier les produits et d'appliquer/supprimer des codes promo.

**Payload**:
```json
{
  "line_items": [
    {
      "product_id": 2,
      "quantity": 1
    }
  ],
  "promo_code": "PROMO20"
}
```

## Champs obligatoires du buyer

Les informations suivantes sont requises pour la création d'un client:
- `email` - Adresse email valide
- `first_name` - Prénom
- `last_name` - Nom de famille
- `address` - Adresse de livraison
- `city` - Ville
- `postal_code` - Code postal (4-10 chiffres)
- `country` - Pays (format ISO)

## Gestion des codes promo

### Application
Envoyez un code promo dans le payload de mise à jour pour l'appliquer automatiquement.

### Suppression
Envoyez une chaîne vide (`"promo_code": ""`) pour supprimer tous les codes promo.

### Validation
Le module vérifie automatiquement:
- L'existence du code promo
- Les limites d'utilisation par client
- La compatibilité avec les produits du panier

## Sécurité

### En-têtes UCP
Toutes les requêtes doivent inclure les en-têtes UCP obligatoires pour l'authentification et la traçabilité.

### Idempotence
Les clés d'idempotence empêchent les doublons et garantissent la cohérence des opérations.

### Validation
Tous les payloads sont validés avant traitement pour prévenir les injections et les erreurs.

## Codes d'erreur

- `400 Bad Request` - Données invalides ou champs manquants
- `401 Unauthorized` - Authentification échouée
- `404 Not Found` - Session ou produit introuvable
- `405 Method Not Allowed` - Méthode HTTP non supportée
- `409 Conflict` - Conflit de clé d'idempotence
- `500 Internal Server Error` - Erreur serveur interne

## Intégration

### Installation
1. Copier le module dans `/modules/ucpwellknown/`
2. Installer depuis l'administration PrestaShop
3. Configurer les clés API et signatures

### Configuration
- Activer/désactiver les logs de sécurité
- Configurer les seuils de validation
- Personnaliser les messages d'erreur

### Tests
- Utiliser les endpoints de test pour valider l'intégration
- Vérifier la gestion des erreurs
- Tester les scénarios de charge

## Support

Pour toute question ou problème d'intégration, consultez la documentation technique ou contactez l'équipe de support.

## Version

- Version actuelle: 1.0.0
- Compatible PrestaShop: 1.7+
- PHP requis: 7.4+

---

*Module développé selon les spécifications du protocole UCP pour garantir une intégration sécurisée et performante.*
