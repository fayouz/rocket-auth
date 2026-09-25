<script setup lang="ts">
/** OpenID Connect settings of an authentication server (client registered at the provider). */
interface OidcFields {
  internalUrl: string
  clientId: string
  clientSecret: string
  scopes: string
  adminGroupDn: string
  linkExistingAccounts: boolean
}

const model = defineModel<OidcFields>({ required: true })
defineProps<{ redirectUri: string, hasSecret: boolean }>()
const toast = useToast()

async function copyRedirectUri(value: string) {
  await navigator.clipboard.writeText(value)
  toast.add({ title: 'URL de retour copiée', color: 'success' })
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <UFormField label="URL de retour à déclarer chez le fournisseur" help="Ajoutez-la aux « redirect URIs » du client.">
      <UInput :model-value="redirectUri" readonly class="w-full font-mono" data-testid="oidc-redirect-uri">
        <template #trailing>
          <UButton icon="i-lucide-copy" color="neutral" variant="link" size="sm" aria-label="Copier" @click="copyRedirectUri(redirectUri)" />
        </template>
      </UInput>
    </UFormField>
    <div class="grid gap-3 sm:grid-cols-2">
      <UFormField label="Client ID" required>
        <UInput v-model="model.clientId" class="w-full font-mono" autocomplete="off" />
      </UFormField>
      <UFormField label="Secret du client" :help="hasSecret ? 'Laisser vide pour conserver le secret actuel.' : undefined">
        <UInput v-model="model.clientSecret" type="password" class="w-full" autocomplete="new-password" :placeholder="hasSecret ? '••••••••' : ''" />
      </UFormField>
    </div>
    <UFormField label="Scopes" help="Doit contenir « openid ». « groups » transmet les groupes de l’utilisateur.">
      <UInput v-model="model.scopes" class="w-full font-mono" />
    </UFormField>
    <UFormField label="Groupe administrateurs (optionnel)" help="Valeur de la revendication « groups » qui donne le rôle administrateur. Vide : les administrateurs sont gérés ici.">
      <UInput v-model="model.adminGroupDn" placeholder="rocket-admins" class="w-full font-mono" />
    </UFormField>
    <UFormField label="URL interne (optionnel)" help="Adresse du fournisseur vue depuis l’API, si elle diffère de l’émetteur (ex. http://auth-api dans Docker).">
      <UInput v-model="model.internalUrl" placeholder="http://auth-api" class="w-full font-mono" />
    </UFormField>
    <USwitch v-model="model.linkExistingAccounts" label="Relier les comptes existants de même email" description="Uniquement pour un fournisseur de confiance, qui vérifie les adresses email." />
  </div>
</template>
