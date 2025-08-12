<?php
class OpenAI_Translate {
    public function translate($text, $prompt, $apiKey) {
        if (!$apiKey || !$prompt || trim($text) === '') return $text;

        $url = "https://api.openai.com/v1/chat/completions";
        $postData = [
            "model" => "gpt-4o",
            "messages" => [
                ["role" => "system", "content" => $prompt],
                ["role" => "user",   "content" => $text]
            ],
            "temperature" => 0.2,
            "max_tokens" => 2000
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer " . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['choices'][0]['message']['content'])) {
                return trim($data['choices'][0]['message']['content']);
            }
        }
        return $text;
    }
}
