#include <stdio.h>
#include <stdlib.h>
#include <time.h>
#include <windows.h>
#include <ctype.h>

int main() {
    SetConsoleOutputCP(CP_UTF8);
    srand((unsigned int)time(NULL));
    char again;

    do {
        int playerHP = 100;
        int computerHP = 100;

        printf("\n--- バトルじゃんけんゲーム開始！ ---\n");

        while (playerHP > 0 && computerHP > 0) {
            char player, computer;

            printf("\nあなたのHP: %d | コンピュータのHP: %d\n", playerHP, computerHP);

            // プレイヤーの手入力
            do {
                printf("選んでください：G = グー、C = チョキ、P = パー：");
                scanf(" %c", &player);
                player = toupper(player);
            } while (!(player == 'G' || player == 'C' || player == 'P'));

            // コンピュータの手決定
            int r = rand() % 3;
            computer = (r == 0) ? 'G' : (r == 1) ? 'C' : 'P';

            printf("あなたの選んだ手：%c\n", player);
            printf("コンピュータの選んだ手：%c\n", computer);

            // 勝敗判定

            // あいこ
            if (player == computer) {
                printf("あいこだ。ダメージなし。\n");

            // プレイヤー勝ち
            } else if ((player == 'G' && computer == 'C') ||
                       (player == 'C' && computer == 'P') ||
                       (player == 'P' && computer == 'G')) {
                printf("やった勝った！\n");
                if (player == 'G') {
                    computerHP -= 40;
                    printf("相手に40ダメージ入ったぞ！\n");
                } else if (player == 'C') {
                    playerHP += 10;
                    if (playerHP > 100) playerHP = 100;
                    printf("体力が10回復したぞ！\n");
                } else if (player == 'P') {
                    computerHP -= 20;
                    printf("相手に20ダメージ入ったぞ！\n");
                }

            // コンピュータ勝ち
            } else {
                printf("負けてしまった…\n");
                if (computer == 'G') {
                    computerHP += 10;
                    if (computerHP > 100) computerHP = 100;
                    printf("相手の体力が10回復してしまった…\n");
                } else if (computer == 'C') {
                    playerHP -= 20;
                    printf("20ダメージを受けてしまった…\n");
                } else if (computer == 'P') {
                    playerHP -= 40;
                    printf("40ダメージを受けてしまった…\n");
                }
            }

            if (playerHP < 0) playerHP = 0;
            if (computerHP < 0) computerHP = 0;
        }

        // 結果表示
        if (playerHP <= 0 && computerHP <= 0) {
            printf("\n引き分けです！\n");
        } else if (playerHP <= 0) {
            printf("\n負けてしまった…\n");
        } else {
            printf("\n君の勝利だ！\n");
        }

        printf("もう一度遊ぶか？ (Y/N): ");
        scanf(" %c", &again);
    } while (again == 'Y' || again == 'y');

    printf("ゲームを終了します。\n");
    return 0;
}
