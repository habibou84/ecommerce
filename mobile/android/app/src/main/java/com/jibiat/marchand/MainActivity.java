package com.jibiat.marchand;

import android.net.Uri;
import android.os.Bundle;
import android.view.View;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.webkit.WebViewCompat;
import androidx.webkit.WebViewFeature;
import com.getcapacitor.Bridge;
import com.getcapacitor.BridgeActivity;
import com.getcapacitor.Logger;
import java.lang.reflect.Method;
import java.util.HashSet;
import java.util.Set;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // Android 15+ affiche l'application sous la barre d'état et les boutons de navigation : la page est
        // décalée de leur hauteur (et de celle du clavier quand il est ouvert) pour ne rien cacher
        View content = findViewById(android.R.id.content);
        ViewCompat.setOnApplyWindowInsetsListener(content, (view, insets) -> {
            Insets bars = insets.getInsets(WindowInsetsCompat.Type.systemBars() | WindowInsetsCompat.Type.displayCutout());
            Insets keyboard = insets.getInsets(WindowInsetsCompat.Type.ime());
            view.setPadding(bars.left, bars.top, bars.right, Math.max(bars.bottom, keyboard.bottom));
            return WindowInsetsCompat.CONSUMED;
        });

        exposeBridgeToStores(getBridge());
    }

    /**
     * Capacitor n'injecte ses modules (notifications…) que sur le domaine de départ (la plateforme) ; la gestion
     * de chaque boutique est sur son sous-domaine (allowNavigation) : on y injecte le même script.
     */
    private static void exposeBridgeToStores(Bridge bridge) {
        if (bridge == null || !WebViewFeature.isFeatureSupported(WebViewFeature.DOCUMENT_START_SCRIPT)) {
            return; // anciens WebView : Capacitor injecte déjà le script dans chaque page
        }
        String mainOrigin = "https://" + Uri.parse(bridge.getServerUrl()).getHost();
        Set<String> origins = new HashSet<>();
        String[] allowed = bridge.getConfig().getAllowNavigation();
        if (allowed != null) {
            for (String host : allowed) {
                String origin = host.startsWith("http") ? host : "https://" + host;
                if (!origin.equalsIgnoreCase(mainOrigin)) {
                    origins.add(origin);
                }
            }
        }
        if (origins.isEmpty()) {
            return;
        }
        try {
            Method getInjector = Bridge.class.getDeclaredMethod("getJSInjector");
            getInjector.setAccessible(true);
            Object injector = getInjector.invoke(bridge);
            Method getScript = injector.getClass().getDeclaredMethod("getScriptString");
            getScript.setAccessible(true);
            String script = (String) getScript.invoke(injector);
            WebViewCompat.addDocumentStartJavaScript(bridge.getWebView(), script, origins);
        } catch (Exception ex) {
            Logger.error("Modules natifs indisponibles sur les boutiques", ex);
        }
    }
}
